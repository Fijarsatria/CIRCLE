-- Skema SQLite CIRCLE. Tabel dibuat otomatis tanpa menghapus data lama.
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'user', bio TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active'
);
CREATE TABLE IF NOT EXISTS circles (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, owner_id INTEGER NOT NULL REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS circle_members (
    circle_id INTEGER NOT NULL REFERENCES circles(id), user_id INTEGER NOT NULL REFERENCES users(id),
    state TEXT NOT NULL DEFAULT 'invited', PRIMARY KEY(circle_id, user_id)
);
CREATE TABLE IF NOT EXISTS moments (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id),
    circle_id INTEGER REFERENCES circles(id), caption TEXT NOT NULL, place TEXT NOT NULL DEFAULT '',
    audience TEXT NOT NULL DEFAULT 'self', theme TEXT NOT NULL DEFAULT 'coffee', photo TEXT,
    latitude REAL, longitude REAL, status TEXT NOT NULL DEFAULT 'published', created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY, reporter_id INTEGER NOT NULL REFERENCES users(id),
    moment_id INTEGER NOT NULL REFERENCES moments(id), reason TEXT NOT NULL,
    state TEXT NOT NULL DEFAULT 'open', resolution TEXT, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS audit (
    id INTEGER PRIMARY KEY, actor_id INTEGER NOT NULL REFERENCES users(id),
    action TEXT NOT NULL, reason TEXT NOT NULL, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS location_sharing (
 circle_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'stopped' CHECK(status IN ('active','paused','stopped')),
 expires_at INTEGER NOT NULL, session_key TEXT NOT NULL DEFAULT '', updated_at INTEGER NOT NULL,
 PRIMARY KEY(circle_id,user_id), FOREIGN KEY(circle_id,user_id) REFERENCES circle_members(circle_id,user_id)
);
CREATE TABLE IF NOT EXISTS location_positions (
 circle_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
 latitude REAL NOT NULL CHECK(latitude BETWEEN -90 AND 90), longitude REAL NOT NULL CHECK(longitude BETWEEN -180 AND 180),
 accuracy_m REAL NOT NULL CHECK(accuracy_m >= 0), observed_at INTEGER NOT NULL, received_at INTEGER NOT NULL,
 PRIMARY KEY(circle_id,user_id), FOREIGN KEY(circle_id,user_id) REFERENCES location_sharing(circle_id,user_id)
);
CREATE INDEX IF NOT EXISTS positions_freshness ON location_positions(received_at);
CREATE INDEX IF NOT EXISTS moments_owner ON moments(user_id);
CREATE INDEX IF NOT EXISTS members_user ON circle_members(user_id, state);
