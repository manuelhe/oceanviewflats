# Admin Operational Workflows: Reservations, Access Credentials, and Manual Bookings

**Issue**: [#29](https://github.com/manuelhe/oceanviewflats/issues/29)  
**Parent Map**: [#24](https://github.com/manuelhe/oceanviewflats/issues/24)  
**Dependencies**: [Issue #26 (Refund Protocol)](../research/mercadopago-refund-protocol.md), [Issue #27 (Schema)](https://github.com/manuelhe/oceanviewflats/issues/27), [Issue #28 (Auth & Security)](admin-authentication-and-security.md)

---

## 1. Overview & Architecture

This specification formalizes the four primary operational workflows within the OceanViewFlats Admin PMS interface (`admin/src/Controllers/ReservationController.php`):
1. **Filtering & Inspecting Reservations and Guest Registries**
2. **Guest Registry Manual Completion & Door Access PIN Overrides**
3. **Manual Reservation Creation (Payment Bypass)**
4. **Cancellations & Automated Mercado Pago Refund Execution**

All operational mutations must:
* Verify the user's active session (`AuthMiddleware`) and CSRF token (`CsrfMiddleware`).
* Execute critical multi-step writes inside a database transaction with row locks (`FOR UPDATE`).
* Record immutable before/after state snapshots via `AuditLogger::log()` into `admin_audit_logs`.
* Return reactive HTMX partials or trigger `HX-Trigger` / `HX-Redirect` response headers.

---

## 2. Workflow 1: Filtering/Searching Reservations & Inspecting Registries

### Controller Endpoints
* **`GET /reservations`**:
  * **Query Parameters**:
    * `property_id`: `'1606' | '1707' | 'all'` (default `'all'`)
    * `status`: `'confirmed' | 'pending_payment' | 'cancelled' | 'all'` (default `'all'`)
    * `registry_status`: `'completed' | 'pending' | 'all'` (default `'all'`)
    * `source`: `'web' | 'cash' | 'bank_transfer' | 'owner_stay' | 'manual_override' | 'all'`
    * `search`: string (matches guest name, email, phone, or `reservation_uid`)
    * `check_in_from` / `check_in_to`: `YYYY-MM-DD`
    * `page`: integer (default 1), `limit`: integer (default 25)
  * **View Response**:
    * Full page `admin/src/Views/reservations/index.php` for direct visits.
    * Table partial `admin/src/Views/reservations/_table.php` if `HTTP_HX_REQUEST` is present.

* **`GET /reservations/{uid}`**:
  * Returns reservation details, payment status, door code, registry status, audit log history, and refund ledger entries.
  * Partial `admin/src/Views/reservations/_detail_drawer.php`.

* **`GET /reservations/{uid}/registry`**:
  * Queries `guest_registries` by `reservation_uid`.
  * Displays structured companion guests (`guests_payload` JSON), document IDs, nationalities, vehicle license plates (`car_plates`), model, IP address, and submission timestamp.
  * Partial `admin/src/Views/reservations/_registry_modal.php`.

### HTMX Interactions
```html
<!-- Live Debounced Search & Filter Form -->
<form hx-get="/reservations" 
      hx-target="#reservations-table-container" 
      hx-trigger="keyup changed delay:300ms from:#search-input, change from:select, change from:input[type=date]"
      hx-indicator="#table-spinner">
    <input type="search" id="search-input" name="search" placeholder="Search guest, email, phone, or UID...">
    <select name="property_id">
        <option value="all">All Flats</option>
        <option value="1606">Apto 1606</option>
        <option value="1707">Apto 1707</option>
    </select>
    <select name="status">
        <option value="all">All Statuses</option>
        <option value="confirmed">Confirmed</option>
        <option value="pending_payment">Pending Payment</option>
        <option value="cancelled">Cancelled</option>
    </select>
</form>

<div id="reservations-table-container">
    <!-- _table.php partial injected here -->
</div>
```

---

## 3. Workflow 2: Manual Registry Completion & Door PIN Overrides

### Controller Endpoints
* **`POST /reservations/{uid}/registry/complete`**:
  * Marks `reservations.registry_completed = 1` and `registry_completed_at = NOW()`.
  * If `door_code` is `NULL`, automatically generates access credential via `DoorCodeGenerator`.
  * Returns updated registry status pill `_registry_status_pill.php`.
* **`POST /reservations/{uid}/door-code/override`**:
  * Sets custom door PIN (e.g. guest requests matching existing PIN or building emergency code).
  * Payload: `door_code` (`string`, regex: `/^[0-9]{4,10}#?$/`).
  * Updates `reservations.door_code = :door_code`.
  * Returns updated PIN container `_door_code_display.php`.
* **`POST /reservations/{uid}/door-code/regenerate`**:
  * Invokes `DoorCodeGenerator::generateForProperty($propertyId)` and updates `reservations.door_code`.

### Validation Rules
* Reservation must exist and must not be in `cancelled` status.
* Door PIN must contain only digits (4–10 chars) optionally followed by `#`.

### Audit Log Schema
```json
{
  "action": "pin_override",
  "entity_type": "reservation",
  "entity_id": "9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d",
  "payload_before": { "door_code": "0160600#" },
  "payload_after": { "door_code": "582910#" }
}
```

---

## 4. Workflow 3: Creating Manual Reservations (Payment Bypass)

### Controller Endpoints
* **`GET /reservations/new`**: Renders modal creation dialog `_create_modal.php`.
* **`POST /reservations/quote-preview`**:
  * Validates date availability via `ReservationLedger->getConflictReasons()`.
  * Calculates suggested total price via `QuoteEngine`.
  * Returns quote preview partial `_quote_preview.php`.
* **`POST /reservations/create-manual`**:
  * **Payload**:
    * `property_id`: `'1606' | '1707'`
    * `check_in`: `YYYY-MM-DD`
    * `check_out`: `YYYY-MM-DD`
    * `guest_name`: string (2-120 chars)
    * `guest_email`: valid email
    * `guest_phone`: string (7-25 chars)
    * `total_price`: decimal >= 0.00
    * `source`: `'cash' | 'bank_transfer' | 'owner_stay' | 'manual_override'`
    * `notes`: optional text
    * `send_confirmation_email`: boolean
  * **Execution**:
    1. Acquire atomic transaction.
    2. Check `ReservationLedger->isAvailable($propertyId, $checkIn, $checkOut)`. If false, abort with HTTP 422: "Selected dates conflict with an existing hold or maintenance block."
    3. Generate `reservation_uid = uuid_v4()`.
    4. Set `status = 'confirmed'`, `payment_status = 'approved'`, `source = :source`.
    5. Generate initial door code via `DoorCodeGenerator`.
    6. Commit transaction.
    7. Emit `admin_audit_logs` entry.
    8. Send email if requested.
    9. Return `HX-Redirect: /reservations/{uid}` or row prepend with success toast.

---

## 5. Workflow 4: Cancellations & Automated Mercado Pago Refunds

### Controller Endpoints
* **`GET /reservations/{uid}/cancel-modal`**:
  * Renders `_cancel_modal.php`.
  * If `mercadopago_payment_id` is present, displays refund options:
    * **Full Refund**: Automatically calculates `refundable = total_price - refunded_amount`.
    * **Partial Refund**: Allows entering specific COP amount (`0 < amount <= refundable`).
    * **No Refund / Policy Retention**: Cancel booking without refunding.
* **`POST /reservations/{uid}/cancel`**:
  * **Payload**:
    * `reason`: string (min 3 chars, required)
    * `refund_type`: `'full' | 'partial' | 'none'`
    * `refund_amount`: decimal (required if `refund_type == 'partial'`)
    * `notes`: optional text

### Execution Protocol & Webhook Invariant
```mermaid
sequenceDiagram
    autonumber
    actor Admin
    participant AdminCtrl as Admin ReservationController
    participant DB as MySQL DB
    participant MP as Mercado Pago Gateway API
    
    Admin->>AdminCtrl: POST /reservations/{uid}/cancel
    AdminCtrl->>DB: BEGIN TRANSACTION & SELECT FOR UPDATE
    alt Online Payment & Refund Requested
        AdminCtrl->>MP: POST /v1/payments/{payment_id}/refunds (with X-Idempotency-Key)
        alt Gateway Error (insufficient balance, dispute)
            MP-->>AdminCtrl: 400/409 Error Response
            AdminCtrl->>DB: ROLLBACK
            AdminCtrl-->>Admin: 422 Unprocessable Entity (Refund Failed)
        else Gateway Success
            MP-->>AdminCtrl: 201 Created (refund_id)
            AdminCtrl->>DB: INSERT INTO reservation_refunds
            AdminCtrl->>DB: UPDATE reservations SET refunded_amount = refunded_amount + :amount
        end
    end
    AdminCtrl->>DB: UPDATE reservations SET status = 'cancelled'
    AdminCtrl->>DB: INSERT INTO admin_audit_logs
    AdminCtrl->>DB: COMMIT
    AdminCtrl-->>Admin: 200 OK (Row Updated & Dates Released)
```

#### Terminal Cancellation Invariant
Once `reservations.status = 'cancelled'`, date availability is immediately released (`Reservation::isHolding()` evaluates to `false`). Any subsequent Mercado Pago asynchronous webhooks received by `public/api/mercadopago-webhook.php` must treat `cancelled` as terminal and **never** overwrite or resurrect the reservation back to `confirmed` or `pending_payment`.
