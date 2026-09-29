# OceanViewFlats

Direct booking platform and guest management system for premium beachfront short-term rental accommodations in Santa Marta, Colombia.

## Language

### Accommodations

**Property**:
A specific physical residential apartment managed by OceanViewFlats and offered for short-term rental. Display names in guest-facing interfaces may be localized to match the target market (e.g., "Apartment", "Apartamento"), but Property remains the canonical domain entity.
_Avoid_: Flat, Apartment, Unit, Condo, Listing

### Stay Duration

**Check-in Date**:
The calendar date on which Guests are authorized to occupy the Property.
_Avoid_: Arrival Date, Start Date

**Check-out Date**:
The calendar date by which all Guests must vacate the Property.
_Avoid_: Departure Date, End Date

**Night**:
The atomic unit of duration and pricing for a Reservation, spanning from afternoon arrival on a date to morning departure the following day. Every Reservation requires a minimum duration of one Night.
_Avoid_: Day, Stay Unit

### Pricing & Currency

**Nightly Rate**:
The base monetary charge for a single Night at a specific Property, dynamically resolved from the seasonal pricing calendar.
_Avoid_: Daily Price, Base Fee, Night Cost

**Quote**:
The calculated, authoritative monetary assessment for a specific Reservation, consisting of the itemized aggregation of each Night's Nightly Rate, plus the mandatory Cleaning Fee and Resort Fee, settling in Settlement Currency (COP).
_Avoid_: Estimate, Bill, Total Price, Cost

**Cleaning Fee**:
A mandatory, flat per-reservation surcharge assessed on every Direct Reservation to cover property preparation and sanitation between Guest stays, established per Property.
_Avoid_: Sanitation Charge, Maid Fee, Turnover Fee

**Resort Fee**:
A mandatory, flat per-reservation administrative and amenities surcharge assessed on every Direct Reservation for building and common-area facilities maintenance.
_Avoid_: Community Fee, Building Fee, Service Surcharge

**Settlement Currency**:
The authoritative currency for all pricing, quotations, and financial transactions, which is Colombian Pesos (COP). Any foreign currencies presented in interfaces are non-authoritative reference conversions.
_Avoid_: Base Currency, Currency Code, FX

**Seasonal Rate Tier**:
A contiguous calendar date window assigned a customized Nightly Rate and minimum stay requirement for a specific Property, taking precedence over default baseline pricing.
_Avoid_: Price Tier, Custom Rate, Rate Rule, Season Block, Pricing Period

### Reservations

**Reservation**:
A time-bounded contractual commitment securing a specific Property for an agreed date range between a Check-in Date and a Check-out Date.
_Avoid_: Booking, Booking Request, Order, Rental

**Direct Reservation**:
A Reservation originated and transacted directly through OceanViewFlats, maintaining full Guest identification, payment records, and access fulfillment workflows.
_Avoid_: Internal Booking, Native Reservation, Website Booking, Direct Booking

**Manual Reservation**:
A Reservation originated directly by an administrator without an external payment gateway (e.g. phone inquiry, external bank wire, or owner occupancy), attributed to an administrative payment source and confirmed immediately to secure calendar dates.
_Avoid_: Offline Booking, Phone Order, Admin Reservation, Walk-in

**Pending Reservation**:
A temporary hold on a Property's calendar created during checkout that preserves dates while awaiting payment confirmation, expiring automatically after either the Standard Hold Window or Voucher Hold Window depending on the selected Payment Method.
_Avoid_: Unpaid Booking, Temporary Hold, Cart

**Standard Hold Window**:
The configurable duration (base standard of 30 minutes, overridable by environment configuration) during which interactive digital checkouts (Card, PSE) hold calendar dates before expiring.
_Avoid_: Timeout, Lock Duration, TTL

**Voucher Hold Window**:
The extended 72-hour duration during which a Pending Reservation holding an unredeemed Cash Voucher reserves calendar dates before expiring automatically.
_Avoid_: Long Hold, Extended Grace Period, Ticket Expiration

**Confirmed Reservation**:
A Reservation backed by verified payment that definitively locks the Property's calendar for the reserved dates.
_Avoid_: Paid Booking, Finalized Reservation

**Cancelled Reservation**:
A previously held or confirmed Reservation that has been voided, releasing the dates back to general availability.
_Avoid_: Void Booking, Expired Hold

**Primary Guest**:
The individual who initiates and pays for the Reservation, holds legal and financial responsibility for the stay, and is contractually required to be one of the staying occupants.
_Avoid_: Booker, Lead Guest, Main Guest, Customer, Client

**Guest**:
Any individual authorized to occupy the Property during a confirmed Reservation, including the Primary Guest and accompanying registered companions.
_Avoid_: Occupant, Companion, Visitor, Tenant

### Payments & Settlement

**Payment Method**:
The financial transaction rail chosen by the Primary Guest during checkout (Credit/Debit Card, PSE bank transfer, or Cash Voucher).
_Avoid_: Payment Type, Gateway Option, Payment Mode

**Instant Settlement**:
Synchronous payment authorization and capture resulting in immediate transition of a Pending Reservation to Confirmed Reservation.
_Avoid_: Direct Pay, Immediate Capture

**Asynchronous Settlement**:
An out-of-band payment workflow (PSE bank transfer or Cash Voucher) where payment confirmation arrives asynchronously via webhook callback.
_Avoid_: Delayed Payment, Offline Order

**Cash Voucher**:
An offline payment instrument (e.g., Efecty) that generates a physical voucher payable at authorized retail locations within the Voucher Hold Window.
_Avoid_: Ticket, Slip, Cash Receipt

**Idempotency Key**:
A unique client-generated token ensuring a payment transaction is processed exactly once, protecting against double-charges during network retries or duplicate submissions.
_Avoid_: Nonce, Transaction Token, Request Hash

**Gateway Refund**:
An automated monetary reversal executed against the payment gateway (Mercado Pago) returning funds to the Primary Guest's original payment rail.
_Avoid_: Charge reversal, payback, return

**Refundable Balance**:
The remaining net monetary balance of a Reservation eligible for refund, defined strictly as Total Price minus cumulative Refunded Amount.
_Avoid_: Available balance, remaining charge, credit

**Policy Retention**:
The portion of the Quote withheld upon cancellation according to cancellation policy terms rather than refunded.
_Avoid_: Cancellation penalty, forfeit, withheld fee

### Channel Synchronization

**Channel Sync**:
The automated bidirectional exchange of calendar availability between OceanViewFlats and external booking channels using the iCalendar protocol to prevent dual-booking conflicts.
_Avoid_: Calendar Sync, iCal Integration, Availability Mirror

**Channel Block**:
A period of calendar unavailability imported from an external Online Travel Agency (e.g., Airbnb) via calendar sync. Channel Blocks do not create Reservation records or Guest identities in OceanViewFlats.
_Avoid_: External Reservation, Airbnb Booking, OTA Booking, Blackout Date

**Maintenance Block**:
A deliberate administrative unavailability hold applied to a Property's calendar for maintenance, repairs, or private host use. Maintenance Blocks do not create Reservation records, Guest identities, or financial transactions, but are projected onto Outbound Feeds to block external channels.
_Avoid_: Admin Block, Blackout Dates, Owner Hold

**Inbound Feed**:
An external calendar subscription periodically fetched by OceanViewFlats to identify and enforce Channel Blocks against direct checkout requests.
_Avoid_: Calendar Import, External Feed, Inbound iCal

**Outbound Feed**:
The dynamic iCalendar stream generated by OceanViewFlats providing external channels with real-time dates blocked by Direct Reservations.
_Avoid_: Calendar Export, ICS Feed, Availability Export

### Guest Onboarding & Fulfillment

**Guest Registry**:
The mandatory record of legal identification, ages, and vehicle details for all staying Guests required by building administration and regulatory compliance before Property access is granted.
_Avoid_: Check-in Form, Registration Card, Guest List

**Guest Guide**:
The digital handbook providing access credentials, arrival instructions, and house rules, unlocked only after the Guest Registry is completed.
_Avoid_: Welcome Pack, House Manual, Instructions Page

**Access Credential**:
The temporal PIN or smart-lock entry code that grants physical entry to the Property during the reserved dates.
_Avoid_: Door Password, Key Code, Room Key

### Communications & Inquiries

**Inquiry**:
A pre-booking question or message submitted by a prospective visitor via the contact form. Inquiries may reference desired stay dates but never create a calendar hold or reservation record; visitors must always complete direct checkout to book.
_Avoid_: Contact Message, Lead, Ticket, Support Request

**Reservation Confirmation**:
The automated transactional email dispatched to the Primary Guest upon payment settlement, containing confirmed stay dates, a payment receipt, and the mandatory link to complete the Guest Registry.
_Avoid_: Booking Receipt, Order Confirmation, Welcome Email

**Registry Report**:
The administrative dispatch generated upon Guest Registry submission containing all registered occupant IDs, ages, and vehicle details, delivered to property management for condominium front-desk clearance.
_Avoid_: Check-in Summary, Guest Roster, Security Notification

**Access Dispatch**:
The communication issued only after the Guest Registry is submitted, delivering the Guest Guide link and temporal Access Credentials for physical property entry.
_Avoid_: Key Code Email, Door PIN Message, Welcome Packet

**Cancellation Notice**:
The automated transactional communication dispatched to the Primary Guest when a Reservation is voided or cancelled, itemizing voided stay dates, financial settlement accounting (Refunded Amount vs. Policy Retention), and customer support channels.
_Avoid_: Cancellation Receipt, Refund Email, Drop Notice

### Administration & Operations

**Admin User**:
An authorized internal operator possessing authenticated credentials to manage Reservations, Access Credentials, rates, and calendar blocks.
_Avoid_: Staff, Employee, Operator, Superuser

**Audit Log**:
An immutable administrative record capturing operational actions (such as door PIN overrides, cancellations, refunds, or rate updates), attributing the change to a specific Admin User with timestamps and payload diffs.
_Avoid_: History, Activity Feed, Event Log, Change Trail


