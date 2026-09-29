# Research: Mercado Pago Refund API and Webhook Reconciliation Protocol

**Document ID**: `docs/research/mercadopago-refund-protocol.md`  
**Author**: OceanViewFlats Engineering  
**Date**: September 2026  
**Status**: Authoritative Research Specification  
**Related Tickets**: 
- Parent Ticket: Issue #24 (`[Map] Administrative Interface for Reservation Management & PMS Operations`)
- Research Ticket: Issue #26 (`Research Mercado Pago Refund API and Webhook Reconciliation Protocol`)
- Blocked Ticket: Issue #29 (`Specify Operational Workflows for Reservations, Access Credentials, and Manual Bookings`)
- Dependent Tickets: Issue #25 (ADR 0005), Issue #27 (Database Schema Additions)

---

## 1. Executive Summary & Research Scope

As part of the OceanViewFlats Administrative Property Management System (PMS) architecture under `admin/` (Issue #24), authorized administrators require the operational capability to cancel reservations, issue full or partial financial refunds, and handle guest disputes directly from the internal dashboard.

This document investigates the primary technical specifications, payload structures, idempotency mechanisms, failure modes, and webhook synchronization patterns for the **Mercado Pago Payments API**. It resolves critical concurrency questions regarding how partial versus full refunds must be persisted in the `reservations` table, and establishes a safe reconciliation protocol when asynchronous webhook events arrive out of sequence, out of band, or concurrent with manual administrative operations.

### Key Findings:
1. **API Primitives**: Full and partial refunds against approved transactions are executed via `POST /v1/payments/{payment_id}/refunds`. A full refund is requested by omitting the `amount` attribute; a partial refund specifies `"amount": <numeric_cop_value>`.
2. **Idempotency Enforcements**: Mercado Pago enforces idempotency via the `X-Idempotency-Key` header with a 24-hour cache TTL. Replaying an identical key with identical payload yields cached responses (`201 Created`); replaying the same key with different payloads triggers `409 Conflict`.
3. **Webhook Behavior**: Mercado Pago does **not** dispatch a distinct `refund` topic. Instead, it dispatches a lightweight notification with `type: "payment"`, `action: "payment.updated"`, and `data.id: "<payment_id>"`. The backend must query `GET /v1/payments/{payment_id}` to inspect the updated state.
4. **Current Codebase Vulnerability**: The existing webhook listener (`public/api/mercadopago-webhook.php`) only reacts to `status === 'approved'`. Because partial refunds leave the parent payment `status` as `'approved'`, an incoming webhook for a partial refund could inadvertently re-confirm a reservation that had previously been marked `cancelled`. Furthermore, full refunds (`status === 'refunded'`) and chargebacks (`status === 'charged_back'`) are currently unhandled and silently ignored.
5. **Reconciliation Invariants**:
   - **Full Refund**: Transitions `reservations.status` to `cancelled`, freeing calendar dates (`Reservation::isHolding()` becomes `false`).
   - **Partial Refund**: Leaves `reservations.status` as `confirmed` (calendar remains locked), increments `reservations.refunded_amount`, and logs an immutable entry in a dedicated `reservation_refunds` table and `admin_audit_logs`.
   - **State Machine Protection**: Once a reservation is in `cancelled` state, incoming webhooks must **never** resurrect it to `confirmed`.

---

## 2. Primary Sources & Documentation Citations

The findings in this specification are grounded directly in official Mercado Pago developer documentation and the OceanViewFlats production codebase:

| Resource | Canonical Reference / Endpoint | Description |
| :--- | :--- | :--- |
| **Mercado Pago Refunds Reference** | `POST https://api.mercadopago.com/v1/payments/{id}/refunds` | Official API specification for issuing full and partial refunds. |
| **Mercado Pago Payment Details** | `GET https://api.mercadopago.com/v1/payments/{id}` | Official API specification for verifying payment status, refund history, and dispute details. |
| **Mercado Pago Webhooks Guide** | Webhooks notifications (`payment.updated`, `topic=payment`) | Documentation covering webhook payloads, retry logic, and server-to-server verification. |
| **Mercado Pago Error Codes** | Gateway response schemas (`error`, `message`, `cause[]`) | Documentation on gateway validation, balance limits, and status constraints. |
| **OceanViewFlats Checkout API** | `public/api/payment.php` | Existing custom transparent payment processor using server-to-server Mercado Pago API. |
| **OceanViewFlats Webhook Listener** | `public/api/mercadopago-webhook.php` | Existing IPN/webhook receiver with server-to-server verification and fulfillment logic. |
| **OceanViewFlats Database Schema** | `scripts/schema.sql` | Production DDL defining `reservations` and `payment_idempotency`. |
| **Domain Entities** | `src/Domain/Reservation/Reservation.php` | Canonical reservation entity defining hold window logic and status mutators. |

---

## 3. Mercado Pago Refund API Specification

### 3.1 Endpoint Routing & Headers
* **HTTP Method**: `POST`
* **Base URL**: `https://api.mercadopago.com`
* **Endpoint Path**: `/v1/payments/{payment_id}/refunds`
* **Target Environment**: Production / Sandbox (governed by the secret access token)

#### Required Request Headers
| Header | Type | Value / Format | Purpose |
| :--- | :--- | :--- | :--- |
| `Authorization` | String | `Bearer <MERCADOPAGO_ACCESS_TOKEN>` | Private authentication credential granting merchant authority. |
| `Content-Type` | String | `application/json` | Enforces JSON request body encoding. |
| `X-Idempotency-Key` | String | String (UUID v4 or deterministic UID) | Protects against network duplicate retries and double-refund requests. |

### 3.2 Request Body Formats

#### Case A: Full Refund (Total Amount Reversal)
To refund the entire remaining refundable balance of a payment, the request body is transmitted either as an empty JSON object `{}` or omitting the `amount` key entirely:

```bash
curl -X POST \
  "https://api.mercadopago.com/v1/payments/9876543210/refunds" \
  -H "Authorization: Bearer <MERCADOPAGO_ACCESS_TOKEN>" \
  -H "X-Idempotency-Key: ref_ovf_a1b2c3d4_full_1727589600" \
  -H "Content-Type: application/json" \
  -d '{}'
```

#### Case B: Partial Refund (Specific Monetary Allocation)
To refund an explicit sub-amount (e.g. refunding a cleaning fee, single-night adjustment, or guest courtesy refund), the `amount` attribute must be provided as a numeric float in Settlement Currency (COP):

```bash
curl -X POST \
  "https://api.mercadopago.com/v1/payments/9876543210/refunds" \
  -H "Authorization: Bearer <MERCADOPAGO_ACCESS_TOKEN>" \
  -H "X-Idempotency-Key: ref_ovf_a1b2c3d4_part_150000_1727589600" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 150000.00
  }'
```

*Constraints on Partial Refund Amount*:
1. `amount > 0`.
2. `amount <= (transaction_amount - transaction_amount_refunded)`.
3. In Colombian Pesos (COP), currency precision is typically handled in whole integers or 2 decimal places. OceanViewFlats standardizes on 2 decimal places (`DECIMAL(10,2)`).

### 3.3 Success Response Schema (`201 Created`)
Upon successfully accepting and creating the refund, Mercado Pago returns HTTP status `201 Created` with the following JSON schema:

```json
{
  "id": 1234567890,
  "payment_id": 9876543210,
  "amount": 150000.00,
  "metadata": {},
  "source": {
    "id": "111111111",
    "name": "OceanViewFlats Admin",
    "type": "collector"
  },
  "date_created": "2026-09-29T10:15:30.000-05:00",
  "unique_sequence_number": null,
  "refund_mode": "standard",
  "adjustment_amount": 0.00,
  "status": "approved",
  "reason": null
}
```

#### Response Attribute Field Dictionary:
* **`id`** (`int64`): The unique identifier assigned to this individual refund transaction. Must be stored in our internal database audit log.
* **`payment_id`** (`int64`): The parent payment identifier on which the refund was levied. Matches `reservations.mercadopago_payment_id`.
* **`amount`** (`float`): The exact monetary amount refunded by this operation.
* **`status`** (`string`): The status of the refund itself. Standard values:
  * `approved`: Refund was immediately authorized and processed.
  * `in_process` / `pending`: Under review or batched by banking rails.
  * `cancelled` / `rejected`: The refund could not be executed.
* **`date_created`** (`ISO 8601 string`): Timestamp when the refund was registered by Mercado Pago.
* **`source`** (`object`): Identity of the initiator (`collector` for merchant-initiated refunds).

---

## 4. Parent Payment Mutation & Status Mechanics

When a refund is approved, Mercado Pago immediately mutates the underlying payment record. Inspecting the payment via `GET /v1/payments/{payment_id}` reveals critical behavioral divergences between Full and Partial refunds:

```
                          ┌────────────────────────┐
                          │   Approved Payment     │
                          │ status = 'approved'    │
                          │ refunded_amount = 0    │
                          └───────────┬────────────┘
                                      │
                   ┌──────────────────┴──────────────────┐
                   ▼                                     ▼
        ┌──────────────────────┐              ┌──────────────────────┐
        │     Full Refund      │              │    Partial Refund    │
        │ status = 'refunded'  │              │ status = 'approved'  │
        │ detail = 'refunded'  │              │ detail = 'partially' │
        │ refunded == total    │              │ 0 < refunded < total │
        └──────────────────────┘              └──────────────────────┘
```

### Detailed Field Comparison:
| Field in `GET /v1/payments/{id}` | After Full Refund | After Partial Refund |
| :--- | :--- | :--- |
| **`status`** | `"refunded"` | **`"approved"`** *(Crucial: Remains approved)* |
| **`status_detail`** | `"refunded"` (or `"acquirer_reimbursed"`) | `"partially_refunded"` |
| **`transaction_amount`** | Unchanged original total (e.g. `1200000.00`) | Unchanged original total (e.g. `1200000.00`) |
| **`transaction_amount_refunded`** | Equals `transaction_amount` (`1200000.00`) | Cumulative refunded sum (e.g. `300000.00`) |
| **`refunds`** Array | Contains all refund objects executed to date. | Contains all refund objects executed to date. |

### Contrast: Refunds vs Cancellations
* **Refund (`POST /v1/payments/{id}/refunds`)**: Applies **only** to payments in `approved` status where funds have settled.
* **Cancellation (`PUT /v1/payments/{id}` with `{"status": "cancelled"}`)**: Applies **only** to payments in `pending` or `in_process` status (such as unredeemed Efecty cash vouchers or pending bank authorization). Attempting to call the refund endpoint on a `pending` payment fails with `cannot_refund`.

---

## 5. Idempotency Guarantees (`X-Idempotency-Key`)

Mercado Pago provides strict, distributed idempotency protection via HTTP request headers:

### 5.1 Mechanics & Lifetime
1. **Cache Duration**: Mercado Pago maintains an idempotency cache for **24 hours** from initial transmission.
2. **Identical Replay**: If an admin double-clicks a refund button, or a network timeout prompts a cURL retry with the **identical key and identical JSON body**, Mercado Pago intercepts the call and returns the cached HTTP response (`201 Created` with the existing refund object). It does **not** generate a second refund.
3. **Mismatched Replay**: If the same `X-Idempotency-Key` is re-submitted with different parameters (e.g. a different amount or different path), Mercado Pago rejects the call with HTTP `409 Conflict` (`idempotency_key_already_used` or `idempotency_validation_failed`).

### 5.2 OceanViewFlats Idempotency Key Convention
In `public/api/payment.php`, payment creation uses `$uid` as the idempotency key. For administrative refunds, we must generate a structured, traceable key format:

$$\text{Key} = \text{"ref\_"} + \text{reservation\_uid} + \text{"\_"} + \text{hash(amount + admin\_id + timestamp\_minute)}$$

Example: `ref_ovf_7f9b2c_150000_u1_1727589600`

This ensures that:
- Accidental double-clicks within the same minute share the same idempotency key, executing exactly once.
- Deliberate secondary partial refunds for different amounts receive unique idempotency keys.

---

## 6. Error Failure Modes & Rejection Matrix

When Mercado Pago rejects a refund request, it returns an HTTP `4xx` status code with a structured JSON payload:
```json
{
  "status": 400,
  "error": "bad_request",
  "message": "refund_amount_exceeds",
  "cause": [
    {
      "code": "400048",
      "description": "The refund amount exceeds the refundable balance",
      "data": null
    }
  ]
}
```

The table below catalogs every documented error code, root cause, and the required administrative recovery strategy:

| HTTP Status | Error / Code | Root Cause | Operator / System Recovery Strategy |
| :--- | :--- | :--- | :--- |
| **`428 Precondition Required`** | `insufficient_money_for_refund` | The merchant Mercado Pago account has insufficient available, cleared balance in COP to pay out the refund. | **Block & Alert Admin**: Display high-priority UI notification: *"Mercado Pago merchant balance insufficient. Deposit funds into Mercado Pago or wait for fresh checkout settlements before retrying."* |
| **`400 Bad Request`** | `refund_amount_exceeds` | The requested partial refund amount is greater than `transaction_amount - transaction_amount_refunded`. | **Validation Block**: Validate on frontend/backend. Show error: *"Amount exceeds maximum refundable balance remaining ($X COP)."* |
| **`409 Conflict`** | `already_refunded` / `order_already_refunded` | The payment has already undergone a full 100% refund. | **Safe No-Op**: Update local reservation status to `cancelled` if not already set, synchronize state, and notify the operator that the payment is already settled as refunded. |
| **`409 Conflict` / `422 Unprocessable`** | `refund_period_exceeded` | The allowable statutory refund window (180 days from payment approval in Colombia) has elapsed. | **Fallback to Manual Wire**: Display: *"180-day automated gateway window expired. Process refund via manual bank wire (Bancolombia) and record manual audit note."* |
| **`409 Conflict` / `422 Unprocessable`** | `cannot_refund` / `payment_in_dispute` / `action_not_allowed_for_current_state` | An active chargeback or mediation dispute has locked the transaction in escrow. | **Dispute Lockout**: Block gateway refund. Alert admin: *"Payment is in dispute with card network. Manage resolution via Mercado Pago Claims Console."* |
| **`422 Unprocessable Entity`** | `payment_not_refundable` | The payment rail does not support programmatic reversal (e.g. redeemed cash voucher or certain offline channels). | **Manual Refund**: Notify administrator to coordinate an offline bank transfer or voucher adjustment. |
| **`400 Bad Request`** | `empty_required_header` | The `X-Idempotency-Key` header was omitted from the HTTP request. | **Software Bug**: Backend client must unconditionally supply a non-empty idempotency key. |
| **`409 Conflict`** | `idempotency_key_already_used` | An idempotency key was re-used with different request body parameters within 24 hours. | **Regenerate Key**: System must issue a distinct idempotency key for distinct operational intents. |
| **`404 Not Found`** | `not_found` / `payment_not_found` | The `mercadopago_payment_id` does not exist or credentials belong to a different environment (sandbox vs prod). | **Data Integrity Check**: Verify payment ID in database and verify `MERCADOPAGO_ACCESS_TOKEN` environment configuration. |

---

## 7. Asynchronous Webhook Architecture

Mercado Pago operates an asynchronous notification webhook service that alerts merchants to transaction state transitions.

### 7.1 Webhook Trigger on Refunds
When a refund is created—whether via the PMS API call or manually performed by a host inside the Mercado Pago web dashboard—Mercado Pago triggers a webhook dispatch:

```json
{
  "id": 9988776655,
  "live_mode": true,
  "type": "payment",
  "date_created": "2026-09-29T10:15:35.000-05:00",
  "user_id": 123456789,
  "api_version": "v1",
  "action": "payment.updated",
  "data": {
    "id": "9876543210"
  }
}
```

### 7.2 Webhook Receipt Rules
1. **Lightweight Notification**: The webhook payload contains **only** the identifier (`data.id`). It never contains trustable financial amounts or status descriptions.
2. **Server-to-Server Verification**: The backend must make an authenticated GET request (`https://api.mercadopago.com/v1/payments/{id}`) to obtain the verified payment object.
3. **Immediate HTTP 200 Acknowledgment**: Mercado Pago requires an HTTP `200 OK` or `201 Created` response within 22 seconds. If a timeout or `5xx` error is returned, Mercado Pago executes exponential retries for up to 48 hours, creating potential storm conditions if long-running external locks occur.

---

## 8. Webhook & PMS Reconciliation Protocol

### 8.1 Vulnerability Analysis of Existing Codebase
Reviewing `public/api/mercadopago-webhook.php` highlights two serious race and integrity risks:

```php
// Existing public/api/mercadopago-webhook.php (Lines 138-160)
if ($status === 'approved') {
    if ($reservation['status'] === 'confirmed') {
        // Ignored
        exit("OK (Already Confirmed)");
    }
    // Resurrects/confirms booking:
    $upStmt = $pdo->prepare("
        UPDATE `reservations` 
        SET `status` = 'confirmed', ...
        WHERE `reservation_uid` = :uid AND `status` != 'confirmed'
    ");
```

#### Risk 1: The "Resurrection Bug" on Partial Refunds
When an administrator executes a partial refund for an active reservation, Mercado Pago fires `payment.updated`.
- When the webhook listener fetches the payment, `status` is still `'approved'`.
- If the reservation was previously cancelled (for example, cancelled with a partial fee retained), the existing code sees `$reservation['status'] != 'confirmed'` and executes the update, **resurrecting a cancelled reservation back to `confirmed`!**

#### Risk 2: Complete Blindness to Full Refunds and Chargebacks
The existing code has:
```php
} else {
    log_webhook_message("Payment status is '{$status}' (not approved). No action taken.");
}
```
If an administrator processes a full refund on the Mercado Pago console, or if a chargeback is lost, `status` arrives as `'refunded'` or `'charged_back'`. The webhook logs a message and takes **zero database action**. The reservation remains permanently `confirmed` in MySQL, and `Reservation::isHolding()` continues blocking the calendar dates forever.

### 8.2 Safe State Machine Transition Invariants

To eliminate these vulnerabilities, the PMS and webhook engine must adhere to strict state transition rules:

| Current DB Status | Verified MP Payment Status | Action / Transition | Calendar Impact |
| :--- | :--- | :--- | :--- |
| `pending_payment` | `approved` | $\rightarrow$ `confirmed` | Holds dates indefinitely. |
| `pending_payment` | `refunded` / `cancelled` | $\rightarrow$ `cancelled` | Releases dates immediately. |
| `confirmed` | `refunded` (Full refund) | $\rightarrow$ `cancelled`, `payment_status = 'refunded'` | **Releases dates immediately**. |
| `confirmed` | `approved` + `partially_refunded` | **STAYS `confirmed`**, update `refunded_amount`, log refund record. | **Dates remain locked**. |
| `confirmed` | `charged_back` | Flag `payment_status = 'charged_back'`, alert admin, disable access credentials. | Under operational review. |
| `cancelled` | `approved` (e.g. from partial refund webhook) | **STAYS `cancelled`** (NO-OP). Log audit note. Never resurrect. | Dates remain available. |
| `cancelled` | `refunded` | **STAYS `cancelled`** (NO-OP). Confirm `payment_status = 'refunded'`. | Dates remain available. |

```
                       ┌──────────────────────┐
                       │   pending_payment    │
                       └──────────┬───────────┘
                                  │
                   ┌──────────────┴──────────────┐
                   │ MP: approved                │ MP: cancelled/refunded
                   ▼                             ▼
        ┌──────────────────────┐      ┌──────────────────────┐
        │      confirmed       │      │      cancelled       │
        └──────────┬───────────┘      │   (Dates Released)   │
                   │                  └──────────────────────┘
                   │ MP: refunded (Full)         ▲
                   └─────────────────────────────┘
                               (Terminal for Webhooks)
```

### 8.3 Concurrency Scenarios & Race Condition Resolution

#### Scenario 1: PMS Admin Action Followed by Webhook
1. Admin clicks *"Refund & Cancel"* in Admin PMS.
2. PMS backend performs synchronous `POST /v1/payments/{payment_id}/refunds`.
3. Gateway returns `201 Created`.
4. Inside a PDO transaction with row lock (`SELECT * FROM reservations WHERE reservation_uid = :uid FOR UPDATE`):
   - Sets `status = 'cancelled'`.
   - Sets `payment_status = 'refunded'`.
   - Inserts record into `reservation_refunds` with `mercadopago_refund_id`.
   - Inserts record into `admin_audit_logs`.
   - Commits transaction.
5. 3 seconds later, Mercado Pago webhook hits `mercadopago-webhook.php`:
   - Fetches payment: `status = 'refunded'`.
   - Inspects `reservations`: already `status = 'cancelled'`.
   - Checks `reservation_refunds`: refund ID already registered.
   - Cleanly logs: `"Ignored: Refund ID 123456 already reconciled by Admin PMS."`
   - Returns HTTP `200 OK`.

#### Scenario 2: Webhook Arrives During Out-of-Band Host Refund (Mercado Pago Console)
1. Host logs into Mercado Pago dashboard directly and issues a full refund.
2. Webhook hits `mercadopago-webhook.php` with `action = 'payment.updated'`.
3. Listener fetches payment: `status = 'refunded'`, `status_detail = 'acquirer_reimbursed'`.
4. Listener opens PDO transaction with `FOR UPDATE`:
   - Inspects DB: reservation is currently `confirmed`.
   - Updates `status = 'cancelled'`, `payment_status = 'refunded'`.
   - Inserts entry into `reservation_refunds` with `source = 'mercadopago_dashboard'`.
   - Inserts automated entry into `admin_audit_logs` attributing action to System/Webhook.
   - Commits transaction.
5. Calendar dates are released immediately, preventing double-booking discrepancies without requiring manual admin input.

#### Scenario 3: Race Condition on Simultaneous Out-of-Order Delivery
If an incoming webhook arrives while the PMS administrative thread is active, MySQL row-level locking (`FOR UPDATE`) serializes the transactions:
- Whichever thread locks the reservation row first writes its mutation.
- The secondary thread evaluates the updated `reservation_refunds` and `status`, preventing duplicate cancellations, duplicate email dispatches, or corrupted refund sums.

---

## 9. Architectural & Database Recommendations

### 9.1 Database Schema Additions (For Issue #27)
To support granular multi-part refunds and bulletproof reconciliation, the following table and column additions must be integrated into `scripts/schema.sql` via `scripts/migrate.php`:

```sql
-- 1. Add cumulative refund tracking to reservations table
ALTER TABLE `reservations`
  ADD COLUMN `refunded_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `total_price`,
  ADD INDEX `idx_payment_status` (`payment_status`);

-- 2. Create dedicated reservation_refunds log table
CREATE TABLE IF NOT EXISTS `reservation_refunds` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reservation_uid` VARCHAR(36) NOT NULL,
  `mercadopago_refund_id` VARCHAR(100) NOT NULL UNIQUE,
  `mercadopago_payment_id` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'approved',
  `reason` VARCHAR(255) DEFAULT NULL,
  `source` ENUM('admin_pms', 'mercadopago_webhook', 'mercadopago_dashboard') NOT NULL DEFAULT 'admin_pms',
  `admin_user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_refund_reservation` (`reservation_uid`),
  INDEX `idx_refund_payment` (`mercadopago_payment_id`),
  CONSTRAINT `fk_refund_reservation` FOREIGN KEY (`reservation_uid`) 
    REFERENCES `reservations` (`reservation_uid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 9.2 Refactoring `public/api/mercadopago-webhook.php`
The webhook receiver must be refactored to parse the `refunds` array and `status_detail`:

```php
// Enhanced Webhook Logic Outline:
$status = $paymentData['status'] ?? '';
$statusDetail = $paymentData['status_detail'] ?? '';
$totalAmount = (float)($paymentData['transaction_amount'] ?? 0);
$refundedAmount = (float)($paymentData['transaction_amount_refunded'] ?? 0);
$refundsList = $paymentData['refunds'] ?? [];

// 1. Full Refund or Cancellation Detected
if ($status === 'refunded' || ($status === 'cancelled' && $reservation['status'] === 'pending_payment') || ($refundedAmount >= $totalAmount && $totalAmount > 0)) {
    if ($reservation['status'] !== 'cancelled') {
        $pdo->beginTransaction();
        $up = $pdo->prepare("
            UPDATE `reservations`
            SET `status` = 'cancelled',
                `payment_status` = 'refunded',
                `refunded_amount` = :refunded_amount,
                `updated_at` = NOW()
            WHERE `reservation_uid` = :uid
        ");
        $up->execute(['refunded_amount' => $refundedAmount, 'uid' => $uid]);
        
        // Sync refund line items to reservation_refunds
        sync_refund_items($pdo, $uid, $paymentId, $refundsList, 'mercadopago_webhook');
        
        $pdo->commit();
        log_webhook_message("Reservation {$uid} CANCELLED via full refund webhook.");
    }
}
// 2. Partial Refund Detected
elseif ($statusDetail === 'partially_refunded' || ($refundedAmount > 0 && $refundedAmount < $totalAmount)) {
    $pdo->beginTransaction();
    $up = $pdo->prepare("
        UPDATE `reservations`
        SET `payment_status` = 'partially_refunded',
            `refunded_amount` = :refunded_amount,
            `updated_at` = NOW()
        WHERE `reservation_uid` = :uid
    ");
    $up->execute(['refunded_amount' => $refundedAmount, 'uid' => $uid]);
    
    // Sync refund line items
    sync_refund_items($pdo, $uid, $paymentId, $refundsList, 'mercadopago_webhook');
    
    $pdo->commit();
    log_webhook_message("Reservation {$uid} recorded PARTIAL REFUND ($refundedAmount COP). Stay remains active.");
}
// 3. Initial Booking Approval
elseif ($status === 'approved') {
    // Only confirm if NOT previously cancelled!
    if ($reservation['status'] === 'pending_payment') {
        // Confirm reservation and fulfill
    }
}
```

### 9.3 Service Boundaries for Admin PMS (Supporting Issue #29)
In accordance with clean architecture principles:
1. **`MercadoPagoRefundClient`** (Infrastructure Port & Adapter):
   - Encapsulates cURL operations to `POST /v1/payments/{payment_id}/refunds`.
   - Handles `X-Idempotency-Key` generation and error payload translation into typed domain exceptions (`InsufficientMerchantBalanceException`, `RefundWindowExpiredException`, `PaymentInDisputeException`).
2. **`ReservationRefundService`** (Domain Use-Case Service):
   - Orchestrates the atomic execution: calls client $\rightarrow$ updates database $\rightarrow$ writes audit log $\rightarrow$ invalidates calendar caches.

---

## 10. Summary & Sign-Off

| Metric | Target Specification |
| :--- | :--- |
| **Refund Route** | `POST /v1/payments/{id}/refunds` |
| **Payload** | Full: `{}` / Partial: `{"amount": <COP>}` |
| **Idempotency** | Header `X-Idempotency-Key` (24h TTL) |
| **Webhook Topic** | `type: "payment"`, `action: "payment.updated"` |
| **Parent Payment `status`** | Full: `'refunded'` / Partial: `'approved'` |
| **Calendar Availability** | Full: Cancelled & released / Partial: Confirmed & locked |
| **Resurrection Protection** | Terminal `cancelled` state preserved against out-of-order webhooks |

This research fulfills all requirements of **Issue #26** and unblocks **Issue #29** for operational implementation within the OceanViewFlats Admin PMS.
