--
-- PostgreSQL database dump
--


-- Dumped from database version 14.22 (Ubuntu 14.22-0ubuntu0.22.04.1)
-- Dumped by pg_dump version 14.22 (Ubuntu 14.22-0ubuntu0.22.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: permissions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('80166d78-077e-4b69-aee5-8c9ebe0f2cfc', 'page.management.labmgmt.view', 'Open Lab Management page', 'page.management', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('faf090bf-5d9e-4cd8-ba4d-83e2520172d8', 'page.management.usermgmt.view', 'Open User Management page', 'page.management', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('f1d5e4f1-7c5c-4fec-9080-6dd9ddf16871', 'page.management.cloudmgmt.view', 'Open Cloud Management page', 'page.management', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('ee094775-79a2-402c-af6b-12faa28d2091', 'page.management.roles.view', 'Open Roles page', 'page.management', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('aaf25ece-540d-4232-8b63-d963b59545bc', 'page.system.status.view', 'Open System Status page', 'page.system', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('a8a5ee71-4b87-4433-b29c-77830cd4a972', 'page.system.taskqueue.view', 'Open Task Queue page', 'page.system', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('a334a315-7721-42cb-a905-b2c5b3005d95', 'page.system.logs.view', 'Open System Logs page', 'page.system', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('8fbac99d-c733-46c0-b055-c78dedb6a267', 'main.folder.create', 'Create folders in Main explorer', 'main', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('667a5eed-61e7-40e4-97d3-8271e653f7a5', 'main.lab.create', 'Create labs in Main explorer', 'main', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('ba9ad2a6-54e0-41d9-9ba9-0cbf41253827', 'main.lab.publish', 'Publish labs (shared/collaboration)', 'main', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('16821708-7d2f-4d42-be55-56d9beb926b1', 'main.lab.share', 'Manage lab shared_with users', 'main', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('27aa4fbc-042a-422d-a5ae-5790c9891c1f', 'cloudmgmt.mapping.manage', 'Create/edit/delete cloud mappings', 'cloudmgmt', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('ab01dbad-d313-4e5a-bbe9-558320fbd088', 'cloudmgmt.pnet.view_all', 'View all PNET from interfaces', 'cloudmgmt', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('7382ea0c-4d25-4a4a-9c26-3b67f8b8fb91', 'users.manage', 'Manage users and sessions', 'users', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('76230d2d-4258-415b-8eff-a9858a59e0db', 'roles.manage', 'Manage roles and role permissions', 'roles', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('c81d237a-a793-48cb-986d-88e27f4db2a6', 'labmgmt.actions', 'Run lab management actions', 'labmgmt', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('67b5da77-27eb-4217-8301-55ab7d4c9937', 'system.logs.read', 'Read system logs content', 'system', '2026-02-24 14:35:21.792799+05', '2026-02-24 14:35:21.792799+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('dc73e44c-8a45-4ce9-b116-37a047a6a265', 'users.manage.non_admin', 'Manage non-admin users and sessions', 'users', '2026-02-24 15:31:09.953456+05', '2026-02-24 15:31:09.953456+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('3f33a3d5-0595-458a-8554-2bb23d2a239f', 'page.system.vm_console.view', 'Open VM Console page', 'page.system', '2026-03-06 12:04:21.667157+05', '2026-03-06 12:04:21.667157+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('a9a7f7f5-7f14-4e0f-a58b-3f6fca0cb29f', 'system.vm_console.files.manage', 'Manage VM console file transfer (upload/download/list)', 'system', '2026-03-13 15:50:00+05', '2026-03-13 15:50:00+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('944078f1-220c-4d5a-b7a2-bd9c69e7b1ed', 'main.lab.topology_lock.manage', 'Manage topology lock and wipe policy for recipients', 'main', '2026-03-06 13:56:48.685404+05', '2026-03-06 13:56:48.685404+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('e48337b0-1bd0-44c3-b001-f13f4e7e094f', 'main.lab.export', 'Export labs from Main explorer', 'main', '2026-03-08 15:53:37.520805+05', '2026-03-08 15:53:37.520805+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('3209159a-3021-4608-a8a5-4001adf7d3fb', 'main.lab.import', 'Import labs into Main explorer', 'main', '2026-03-08 15:53:37.520805+05', '2026-03-08 15:53:37.520805+05');
INSERT INTO public.permissions (id, code, title, category, created_at, updated_at) VALUES ('edfba7ac-9ce4-4701-b034-a2dc3a03d188', 'main.users.browse_all', 'Full access to all users labs', 'main', '2026-03-08 17:01:29.317993+05', '2026-03-08 17:01:29.317993+05');


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.roles (name, id) VALUES ('user', '849a50cf-9050-48f9-8226-4d45326a517e');
INSERT INTO public.roles (name, id) VALUES ('admin', 'b5d87e3a-b09a-4b5d-9ce3-9b428cca388d');
INSERT INTO public.roles (name, id) VALUES ('teacher', '2de0d93a-6ff4-4b25-be79-ee6c74291544');


--
-- Data for Name: role_permissions; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '27aa4fbc-042a-422d-a5ae-5790c9891c1f', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'ab01dbad-d313-4e5a-bbe9-558320fbd088', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'c81d237a-a793-48cb-986d-88e27f4db2a6', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '8fbac99d-c733-46c0-b055-c78dedb6a267', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '667a5eed-61e7-40e4-97d3-8271e653f7a5', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'ba9ad2a6-54e0-41d9-9ba9-0cbf41253827', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '16821708-7d2f-4d42-be55-56d9beb926b1', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'f1d5e4f1-7c5c-4fec-9080-6dd9ddf16871', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '80166d78-077e-4b69-aee5-8c9ebe0f2cfc', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'ee094775-79a2-402c-af6b-12faa28d2091', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('849a50cf-9050-48f9-8226-4d45326a517e', '8fbac99d-c733-46c0-b055-c78dedb6a267', '2026-02-24 22:34:15.290875+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('849a50cf-9050-48f9-8226-4d45326a517e', '667a5eed-61e7-40e4-97d3-8271e653f7a5', '2026-02-24 22:34:15.290875+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'faf090bf-5d9e-4cd8-ba4d-83e2520172d8', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'a334a315-7721-42cb-a905-b2c5b3005d95', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'aaf25ece-540d-4232-8b63-d963b59545bc', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'a8a5ee71-4b87-4433-b29c-77830cd4a972', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '76230d2d-4258-415b-8eff-a9858a59e0db', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '67b5da77-27eb-4217-8301-55ab7d4c9937', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '7382ea0c-4d25-4a4a-9c26-3b67f8b8fb91', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'dc73e44c-8a45-4ce9-b116-37a047a6a265', '2026-02-25 08:16:03.559019+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('849a50cf-9050-48f9-8226-4d45326a517e', 'aaf25ece-540d-4232-8b63-d963b59545bc', '2026-02-25 16:33:23.638619+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('849a50cf-9050-48f9-8226-4d45326a517e', 'a8a5ee71-4b87-4433-b29c-77830cd4a972', '2026-02-25 16:33:23.638619+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '3f33a3d5-0595-458a-8554-2bb23d2a239f', '2026-03-06 12:04:21.668772+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'a9a7f7f5-7f14-4e0f-a58b-3f6fca0cb29f', '2026-03-13 15:50:00+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '944078f1-220c-4d5a-b7a2-bd9c69e7b1ed', '2026-03-06 13:56:48.686591+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'e48337b0-1bd0-44c3-b001-f13f4e7e094f', '2026-03-08 15:53:37.522414+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', '3209159a-3021-4608-a8a5-4001adf7d3fb', '2026-03-08 15:53:37.522414+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('b5d87e3a-b09a-4b5d-9ce3-9b428cca388d', 'edfba7ac-9ce4-4701-b034-a2dc3a03d188', '2026-03-08 17:01:29.319546+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', '27aa4fbc-042a-422d-a5ae-5790c9891c1f', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', 'ab01dbad-d313-4e5a-bbe9-558320fbd088', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', 'c81d237a-a793-48cb-986d-88e27f4db2a6', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', '8fbac99d-c733-46c0-b055-c78dedb6a267', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', '667a5eed-61e7-40e4-97d3-8271e653f7a5', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', 'ba9ad2a6-54e0-41d9-9ba9-0cbf41253827', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', '16821708-7d2f-4d42-be55-56d9beb926b1', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', '944078f1-220c-4d5a-b7a2-bd9c69e7b1ed', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', 'edfba7ac-9ce4-4701-b034-a2dc3a03d188', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', '80166d78-077e-4b69-aee5-8c9ebe0f2cfc', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', 'aaf25ece-540d-4232-8b63-d963b59545bc', '2026-03-10 08:14:16.599697+05');
INSERT INTO public.role_permissions (role_id, permission_id, created_at) VALUES ('2de0d93a-6ff4-4b25-be79-ee6c74291544', 'a8a5ee71-4b87-4433-b29c-77830cd4a972', '2026-03-10 08:14:16.599697+05');


--
-- Data for Name: task_queue_settings; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.task_queue_settings (id, power_parallel_limit, updated_at, worker_slots, fast_stop_vios, start_worker_slots, stop_worker_slots, check_worker_slots) VALUES (1, 1, '2026-03-10 08:29:26.882967+05', 4, true, 1, 1, 2);


--
-- PostgreSQL database dump complete
--
