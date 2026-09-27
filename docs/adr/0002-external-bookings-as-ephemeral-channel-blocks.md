# External Bookings Treated Exclusively as Ephemeral Channel Blocks

External Online Travel Agency bookings (e.g. from Airbnb) are ingested purely as ephemeral calendar date blocks (`Channel Block`) cached on disk and checked in-memory during availability validation. They are never written to the MySQL `reservations` table. This keeps the database as an authoritative ledger strictly for direct guests and financial transactions, avoiding synthetic records and complex bidirectional two-way sync state mutations.
