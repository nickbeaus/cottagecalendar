-- Cottage Calendar schema, converted from the original MySQL tables.
-- Times (created_at) are stored as UTC text, "YYYY-MM-DD HH:MM:SS".
-- AUTOINCREMENT keeps deleted ids from being reused: booking ids are part of the
-- iCal event ids, and a reused one would confuse subscribed phone calendars.

-- end_date is the day after the last night (the departure day + 1), as FullCalendar expects.
CREATE TABLE bookings (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    renter_name  TEXT NOT NULL CHECK (length(renter_name) BETWEEN 1 AND 100),
    cottage_type TEXT NOT NULL CHECK (cottage_type IN ('front', 'back', 'both')),
    start_date   TEXT NOT NULL,
    end_date     TEXT NOT NULL,
    created_at   TEXT DEFAULT CURRENT_TIMESTAMP,
    CHECK (end_date > start_date)
);
CREATE INDEX idx_bookings_dates ON bookings (start_date, end_date);

-- Recent Activity and the digest. action is Created, Updated, Canceled, Exported or Viewed
-- (Viewed is never shown).
CREATE TABLE changelog (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    action     TEXT NOT NULL,
    details    TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    ip_address TEXT
);
CREATE INDEX idx_changelog_created ON changelog (created_at);

-- Wrong PINs and passwords, for lockouts. scope: pin, ical, export or setup.
CREATE TABLE auth_failures (
    id           INTEGER PRIMARY KEY,
    ip_address   TEXT NOT NULL,
    scope        TEXT NOT NULL,
    attempted_at INTEGER NOT NULL  -- unix time
);
CREATE INDEX idx_auth_failures ON auth_failures (ip_address, scope, attempted_at);

-- setup_code, setup_done, digest_last_date, digest_retry_after
CREATE TABLE app_settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
