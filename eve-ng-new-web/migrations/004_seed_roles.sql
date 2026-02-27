INSERT INTO roles(name) VALUES ('admin') ON CONFLICT (name) DO NOTHING;
INSERT INTO roles(name) VALUES ('user') ON CONFLICT (name) DO NOTHING;
