# External Platform Reservations, Guest Onboarding, and Ledger Absorption (Amending ADR 0002)

## Context

[ADR 0001](0001-mandatory-registry-before-access.md) mandates that Access Credentials and the Guest Guide are strictly withheld until the Primary Guest completes the Guest Registry for all staying occupants, enforcing statutory Colombian hospitality regulations and Playa Salguero condominium security clearance.

[ADR 0002](0002-external-bookings-as-ephemeral-channel-blocks.md) established that external Online Travel Agency (OTA) bookings (specifically Airbnb) are ingested purely as ephemeral calendar date blocks (`Channel Block`) cached on disk and evaluated in-memory during availability checks. Under ADR 0002, external bookings were never written to the MySQL `reservations` table in order to keep the database an authoritative ledger strictly for direct financial transactions.

While ADR 0002 successfully prevented automated iCal polling from cluttering the database with synthetic or incomplete records, it created a severe operational barrier: guests booking through Airbnb still legally require condominium security clearance (Guest Registry) and property arrival instructions (Guest Guide & door code PIN). Because both `GuestRegistry` and `GuideAccessService` are cryptographically and relationally keyed to authoritative `reservation_uid` records in the `reservations` table, property operators had no system-supported way to onboard Airbnb guests, capture their legal IDs, or dispatch access credentials without bypassing ADR 0001 or resorting to error-prone manual spreadsheets.

Furthermore, Airbnb guests are primarily contacted through the Airbnb messaging inbox where guest email addresses are masked or unmonitored. Operators required a streamlined workflow to generate pre-filled onboarding links and copyable chat snippets directly from the administrative platform.

## Decision

We amend ADR 0002 to introduce **External Reservations** as first-class domain entities created on explicit administrative intent, while retaining ephemeral in-memory caching for raw background sync feeds.

### 1. Persistence Model: External Reservations

Inbound calendar feed polling continues to store raw availability blocks as ephemeral `ChannelBlock` objects on disk without database writes. However, when an administrator onboards an external booking or manually registers an Airbnb stay, OceanViewFlats creates an authoritative `Reservation` entity in the MySQL `reservations` table with:
- **`reservation_uid`**: Generated with the dedicated prefix `res-abnb-<hex>` (e.g., `res-abnb-4f8a12bc9d01`) to distinguish external bookings in logs, audit records, and guest lookups.
- **`source`**: Stored as `'airbnb'`.
- **`status`**: Initialized immediately as `CONFIRMED`.
- **`payment_method_id`**: Set to `'external_ota'`.
- **`payment_status`**: Marked as `'approved'`.
- **`total_price`**: Set to the host payout in COP (entered by the operator, falling back to 0.00 COP when unstated or pending).
- **`external_confirmation_code`**: A dedicated column (`VARCHAR(64) NULL`) storing the platform confirmation code (e.g., `HM3ABC1234`) for rapid administrative search and operator cross-referencing.
- **`channel_block_uid`**: A dedicated column (`VARCHAR(128) NULL`) storing the iCalendar VEVENT UID from the inbound feed, explicitly linking the registered reservation with its originating calendar block.

### 2. Reservation Ledger Absorption & Conflict Resolution

The `ReservationLedger` availability engine is enhanced to recognize external reservation creation:
- When checking availability for a reservation with `source = 'airbnb'`, any overlapping `ChannelBlock` originating from the same platform (`airbnb`) for the target property is absorbed as matching external inventory rather than rejected as a double-booking collision.
- Conflicting direct reservations, maintenance holds (ADR 0006), or channel blocks from different platforms continue to trigger strict availability rejections.

### 3. Outbound Feed Echo Prevention

The outbound iCalendar feed endpoint (`/api/ical.php`) queries active reservations and maintenance holds to project unavailability to external platforms. To prevent circular booking echoes back into Airbnb, the feed generation logic strictly filters out reservations whose `source` matches the target channel (`source = 'airbnb'`).

### 4. Strict Enforcement of ADR 0001 (Two-Stage Fulfillment)

External reservations strictly enforce ADR 0001:
- **Stage 1 (Guest Registry Invitation)**: Upon reservation creation, the guest is issued a pre-filled Guest Registry link (`https://oceanviewflats.com/registry/?property=...&code=res-abnb-...`). Access Credentials (door code PIN) and the Guest Guide remain locked.
- **Stage 2 (Access Dispatch)**: Only after the Primary Guest submits legal occupant identification (or if the operator toggles "Pre-mark registry completed" after manually verifying identity on Airbnb), the Guest Guide and Access Credentials unlock.

### 5. Multi-Language Chat Snippet Generator & Admin UX

Because Airbnb hosts primarily communicate with guests via Airbnb chat:
- **Bilingual Chat Snippet Generator**: The admin detail drawer and creation confirmation modal provide an interactive copyable message widget with an English/Spanish toggle (`EN` / `ES`). The operator can copy a pre-formatted message containing the guest's name, property, dates, and direct registration link with a single click.
- **Optional Email Delivery**: Operators may optionally check "Send confirmation email" if a valid unmasked email address was provided by the guest.
- **Dual Entry Points**:
  1. **Calendar Blocks Panel (`/calendar-blocks`)**: Detected Airbnb blocks display an "Onboard Guest" action button that opens the creation modal pre-populated with property ID, check-in date, check-out date, and `channel_block_uid`. Once created, the channel block displays an "Onboarded" badge linking to the reservation.
  2. **Reservations Dashboard (`/reservations/new`)**: The manual reservation modal supports "Airbnb" as a distinct booking source with dedicated input for the Airbnb Confirmation Code.

## Consequences

### Positive
- **Statutory & Security Compliance**: Guarantees all Airbnb occupants complete the legal Guest Registry before receiving door codes, fully satisfying Colombian hospitality law and Playa Salguero condominium regulations.
- **Unified Domain Pipeline**: Reuses existing `GuideAccessService`, `GuestLifecycleFulfillmentService`, and `guest_registries` tables without maintaining separate duplicate pipelines for direct vs. external guests.
- **Zero Echo Conflicts**: Prevents circular feed loops on outbound iCal exports and eliminates self-collision false positives on the ledger.
- **Operator Velocity**: Reduces guest onboarding from a multi-step manual chore to a one-click copyable chat template workflow.

### Neutral / Trade-offs
- Requires database migration adding `external_confirmation_code` and `channel_block_uid` to the `reservations` table.
- The `reservations` table now holds non-transacted stays where `total_price` reflects host payout or 0.00 COP rather than direct credit card settlements.
