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
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: set_lab_folders_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_lab_folders_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: set_lab_networks_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_lab_networks_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: set_lab_node_ports_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_lab_node_ports_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: set_lab_nodes_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_lab_nodes_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: set_lab_objects_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_lab_objects_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: set_lab_task_groups_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_lab_task_groups_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: set_lab_tasks_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_lab_tasks_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: set_labs_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_labs_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


--
-- Name: set_users_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_users_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$;


--
-- Name: touch_folder_ancestors(uuid); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.touch_folder_ancestors(start_folder_id uuid) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    current_id UUID;
BEGIN
    current_id := start_folder_id;
    WHILE current_id IS NOT NULL LOOP
        UPDATE lab_folders
        SET updated_at = NOW()
        WHERE id = current_id;

        SELECT parent_id
        INTO current_id
        FROM lab_folders
        WHERE id = current_id;
    END LOOP;
END;
$$;


--
-- Name: trg_lab_folders_touch_parent(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_lab_folders_touch_parent() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.parent_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(NEW.parent_id);
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.parent_id IS DISTINCT FROM OLD.parent_id THEN
            IF OLD.parent_id IS NOT NULL THEN
                PERFORM touch_folder_ancestors(OLD.parent_id);
            END IF;
            IF NEW.parent_id IS NOT NULL THEN
                PERFORM touch_folder_ancestors(NEW.parent_id);
            END IF;
        ELSIF NEW.parent_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(NEW.parent_id);
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        IF OLD.parent_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(OLD.parent_id);
        END IF;
        RETURN OLD;
    END IF;

    RETURN NULL;
END;
$$;


--
-- Name: trg_labs_touch_folder_chain(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_labs_touch_folder_chain() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.folder_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(NEW.folder_id);
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF NEW.folder_id IS DISTINCT FROM OLD.folder_id THEN
            IF OLD.folder_id IS NOT NULL THEN
                PERFORM touch_folder_ancestors(OLD.folder_id);
            END IF;
            IF NEW.folder_id IS NOT NULL THEN
                PERFORM touch_folder_ancestors(NEW.folder_id);
            END IF;
        ELSIF NEW.folder_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(NEW.folder_id);
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'DELETE' THEN
        IF OLD.folder_id IS NOT NULL THEN
            PERFORM touch_folder_ancestors(OLD.folder_id);
        END IF;
        RETURN OLD;
    END IF;

    RETURN NULL;
END;
$$;


--
-- Name: trg_touch_labs_updated_from_activity(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_touch_labs_updated_from_activity() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    affected_lab UUID;
BEGIN
    IF TG_OP = 'DELETE' THEN
        affected_lab := OLD.lab_id;
    ELSE
        affected_lab := NEW.lab_id;
    END IF;

    IF affected_lab IS NOT NULL THEN
        UPDATE labs
        SET updated_at = NOW()
        WHERE id = affected_lab;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;


--
-- Name: trg_touch_labs_updated_from_ports(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.trg_touch_labs_updated_from_ports() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    affected_node UUID;
    affected_lab UUID;
BEGIN
    IF TG_OP = 'DELETE' THEN
        affected_node := OLD.node_id;
    ELSE
        affected_node := NEW.node_id;
    END IF;

    IF affected_node IS NULL THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    SELECT lab_id
    INTO affected_lab
    FROM lab_nodes
    WHERE id = affected_node;

    IF affected_lab IS NOT NULL THEN
        UPDATE labs
        SET updated_at = NOW()
        WHERE id = affected_lab;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: auth_sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auth_sessions (
    token character(64) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    ip inet,
    user_agent text,
    last_activity timestamp with time zone DEFAULT now() NOT NULL,
    user_id uuid NOT NULL,
    ended_at timestamp with time zone,
    ended_reason character varying(32)
);


--
-- Name: cloud_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cloud_users (
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    cloud_id uuid NOT NULL,
    user_id uuid NOT NULL
);


--
-- Name: clouds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.clouds (
    name character varying(128) NOT NULL,
    pnet character varying(64) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    CONSTRAINT clouds_pnet_format_chk CHECK (((pnet)::text ~ '^pnet[0-9]+$'::text))
);


--
-- Name: lab_check_grade_scales; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_check_grade_scales (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lab_id uuid NOT NULL,
    min_percent numeric(5,2) NOT NULL,
    grade_label character varying(64) NOT NULL,
    order_index integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT lab_check_grade_scales_min_percent_range CHECK (((min_percent >= 0.00) AND (min_percent <= 100.00)))
);


--
-- Name: lab_check_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_check_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lab_id uuid NOT NULL,
    node_id uuid NOT NULL,
    title character varying(255) NOT NULL,
    transport character varying(16) DEFAULT 'auto'::character varying NOT NULL,
    shell_type character varying(16) DEFAULT 'auto'::character varying NOT NULL,
    command_text text NOT NULL,
    match_mode character varying(24) DEFAULT 'contains'::character varying NOT NULL,
    expected_text text NOT NULL,
    hint_text text DEFAULT ''::text NOT NULL,
    show_expected_to_learner boolean DEFAULT false NOT NULL,
    show_output_to_learner boolean DEFAULT false NOT NULL,
    points integer DEFAULT 1 NOT NULL,
    timeout_seconds integer DEFAULT 12 NOT NULL,
    is_enabled boolean DEFAULT true NOT NULL,
    order_index integer DEFAULT 0 NOT NULL,
    ssh_host character varying(255),
    ssh_port integer,
    ssh_username character varying(255),
    ssh_password text,
    created_by uuid,
    updated_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    is_required boolean DEFAULT false NOT NULL,
    CONSTRAINT lab_check_items_match_mode_allowed CHECK (((match_mode)::text = ANY ((ARRAY['contains'::character varying, 'equals'::character varying, 'regex'::character varying, 'not_contains'::character varying])::text[]))),
    CONSTRAINT lab_check_items_points_range CHECK (((points >= 0) AND (points <= 100000))),
    CONSTRAINT lab_check_items_shell_type_allowed CHECK (((shell_type)::text = ANY ((ARRAY['auto'::character varying, 'ios'::character varying, 'sh'::character varying, 'cmd'::character varying, 'powershell'::character varying])::text[]))),
    CONSTRAINT lab_check_items_ssh_port_range CHECK (((ssh_port IS NULL) OR ((ssh_port >= 1) AND (ssh_port <= 65535)))),
    CONSTRAINT lab_check_items_timeout_range CHECK (((timeout_seconds >= 1) AND (timeout_seconds <= 240))),
    CONSTRAINT lab_check_items_transport_allowed CHECK (((transport)::text = ANY ((ARRAY['auto'::character varying, 'console'::character varying, 'ssh'::character varying])::text[])))
);


--
-- Name: lab_check_run_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_check_run_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    run_id uuid NOT NULL,
    lab_id uuid NOT NULL,
    check_item_id uuid,
    node_id uuid,
    node_name character varying(255) DEFAULT ''::character varying NOT NULL,
    check_title character varying(255) DEFAULT ''::character varying NOT NULL,
    transport character varying(16) DEFAULT 'auto'::character varying NOT NULL,
    shell_type character varying(16) DEFAULT 'auto'::character varying NOT NULL,
    command_text text DEFAULT ''::text NOT NULL,
    expected_text text DEFAULT ''::text NOT NULL,
    match_mode character varying(24) DEFAULT 'contains'::character varying NOT NULL,
    hint_text text DEFAULT ''::text NOT NULL,
    show_expected_to_learner boolean DEFAULT false NOT NULL,
    show_output_to_learner boolean DEFAULT false NOT NULL,
    status character varying(16) DEFAULT 'failed'::character varying NOT NULL,
    is_passed boolean DEFAULT false NOT NULL,
    points integer DEFAULT 0 NOT NULL,
    earned_points integer DEFAULT 0 NOT NULL,
    output_text text,
    error_text text,
    duration_ms integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT lab_check_run_items_earned_points_range CHECK (((earned_points >= 0) AND (earned_points <= 100000))),
    CONSTRAINT lab_check_run_items_match_mode_allowed CHECK (((match_mode)::text = ANY ((ARRAY['contains'::character varying, 'equals'::character varying, 'regex'::character varying, 'not_contains'::character varying])::text[]))),
    CONSTRAINT lab_check_run_items_points_range CHECK (((points >= 0) AND (points <= 100000))),
    CONSTRAINT lab_check_run_items_shell_type_allowed CHECK (((shell_type)::text = ANY ((ARRAY['auto'::character varying, 'ios'::character varying, 'sh'::character varying, 'cmd'::character varying, 'powershell'::character varying])::text[]))),
    CONSTRAINT lab_check_run_items_status_allowed CHECK (((status)::text = ANY ((ARRAY['passed'::character varying, 'failed'::character varying, 'error'::character varying, 'skipped'::character varying])::text[]))),
    CONSTRAINT lab_check_run_items_transport_allowed CHECK (((transport)::text = ANY ((ARRAY['auto'::character varying, 'console'::character varying, 'ssh'::character varying])::text[])))
);


--
-- Name: lab_check_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_check_runs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lab_id uuid NOT NULL,
    started_by uuid,
    started_by_username character varying(255) DEFAULT ''::character varying NOT NULL,
    status character varying(16) DEFAULT 'running'::character varying NOT NULL,
    total_items integer DEFAULT 0 NOT NULL,
    passed_items integer DEFAULT 0 NOT NULL,
    failed_items integer DEFAULT 0 NOT NULL,
    error_items integer DEFAULT 0 NOT NULL,
    total_points integer DEFAULT 0 NOT NULL,
    earned_points integer DEFAULT 0 NOT NULL,
    score_percent numeric(5,2) DEFAULT 0.00 NOT NULL,
    grade_label character varying(64),
    started_at timestamp with time zone DEFAULT now() NOT NULL,
    finished_at timestamp with time zone,
    duration_ms integer DEFAULT 0 NOT NULL,
    CONSTRAINT lab_check_runs_score_percent_range CHECK (((score_percent >= 0.00) AND (score_percent <= 100.00))),
    CONSTRAINT lab_check_runs_status_allowed CHECK (((status)::text = ANY ((ARRAY['running'::character varying, 'completed'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: lab_check_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_check_settings (
    lab_id uuid NOT NULL,
    grading_enabled boolean DEFAULT true NOT NULL,
    pass_percent numeric(5,2) DEFAULT 60.00 NOT NULL,
    updated_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT lab_check_settings_pass_percent_range CHECK (((pass_percent >= 0.00) AND (pass_percent <= 100.00)))
);


--
-- Name: lab_check_task_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_check_task_items (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lab_id uuid NOT NULL,
    task_text text NOT NULL,
    is_enabled boolean DEFAULT true NOT NULL,
    order_index integer DEFAULT 0 NOT NULL,
    created_by uuid,
    updated_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: lab_check_task_marks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_check_task_marks (
    lab_id uuid NOT NULL,
    task_item_id uuid NOT NULL,
    user_id uuid NOT NULL,
    is_done boolean DEFAULT true NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: lab_check_task_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_check_task_settings (
    lab_id uuid NOT NULL,
    intro_text text DEFAULT ''::text NOT NULL,
    updated_by uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: lab_collab_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_collab_tokens (
    token text NOT NULL,
    lab_id uuid NOT NULL,
    user_id uuid NOT NULL,
    issued_at timestamp with time zone DEFAULT now() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    last_used_at timestamp with time zone,
    revoked_at timestamp with time zone
);


--
-- Name: lab_folders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_folders (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    owner_user_id uuid NOT NULL,
    parent_id uuid,
    name character varying(255) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: lab_networks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_networks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lab_id uuid NOT NULL,
    name character varying(255),
    network_type character varying(64) NOT NULL,
    left_pos integer,
    top_pos integer,
    visibility smallint DEFAULT 1 NOT NULL,
    icon character varying(255),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: lab_node_ports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_node_ports (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    node_id uuid NOT NULL,
    name character varying(64) NOT NULL,
    port_type character varying(32) NOT NULL,
    network_id uuid,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: lab_nodes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_nodes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lab_id uuid NOT NULL,
    name character varying(255) NOT NULL,
    node_type character varying(64) NOT NULL,
    template character varying(128),
    image character varying(255),
    icon character varying(255),
    console character varying(32),
    left_pos integer,
    top_pos integer,
    delay_ms integer DEFAULT 0 NOT NULL,
    ethernet_count integer,
    serial_count integer,
    cpu integer,
    ram_mb integer,
    nvram_mb integer,
    first_mac macaddr,
    qemu_options text,
    qemu_version character varying(32),
    qemu_arch character varying(32),
    qemu_nic character varying(64),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    is_running boolean DEFAULT false NOT NULL,
    power_state character varying(16) DEFAULT 'stopped'::character varying NOT NULL,
    last_error text,
    power_updated_at timestamp with time zone DEFAULT now() NOT NULL,
    runtime_pid integer,
    runtime_console_port integer,
    runtime_started_at timestamp with time zone,
    runtime_stopped_at timestamp with time zone,
    runtime_check_console_port integer,
    CONSTRAINT lab_nodes_power_state_chk CHECK (((power_state)::text = ANY ((ARRAY['stopped'::character varying, 'starting'::character varying, 'running'::character varying, 'stopping'::character varying, 'error'::character varying])::text[])))
);


--
-- Name: lab_objects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_objects (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lab_id uuid NOT NULL,
    object_type character varying(32) NOT NULL,
    name character varying(255),
    data_base64 text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: lab_shared_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_shared_users (
    lab_id uuid NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    user_id uuid NOT NULL
);


--
-- Name: lab_task_groups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_task_groups (
    id character varying(80) NOT NULL,
    lab_id uuid NOT NULL,
    action character varying(32) NOT NULL,
    status character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    label character varying(160),
    requested_by_user_id uuid,
    requested_by character varying(255),
    total integer DEFAULT 0 NOT NULL,
    queued integer DEFAULT 0 NOT NULL,
    running integer DEFAULT 0 NOT NULL,
    done integer DEFAULT 0 NOT NULL,
    failed integer DEFAULT 0 NOT NULL,
    skipped integer DEFAULT 0 NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    progress_pct integer DEFAULT 0 NOT NULL,
    error_text text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    started_at timestamp with time zone,
    finished_at timestamp with time zone,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT lab_task_groups_action_chk CHECK (((action)::text = ANY ((ARRAY['start'::character varying, 'stop'::character varying])::text[]))),
    CONSTRAINT lab_task_groups_status_chk CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'done'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: lab_tasks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.lab_tasks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    lab_id uuid NOT NULL,
    node_id uuid NOT NULL,
    action character varying(32) NOT NULL,
    status character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    result_data jsonb DEFAULT '{}'::jsonb NOT NULL,
    requested_by_user_id uuid,
    requested_by character varying(255),
    error_text text,
    attempts integer DEFAULT 0 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    started_at timestamp with time zone,
    finished_at timestamp with time zone,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT lab_tasks_action_chk CHECK (((action)::text = ANY ((ARRAY['start'::character varying, 'stop'::character varying, 'lab_check'::character varying])::text[]))),
    CONSTRAINT lab_tasks_status_chk CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'done'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: labs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.labs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name character varying(255) NOT NULL,
    is_shared boolean DEFAULT false NOT NULL,
    is_mirror boolean DEFAULT false NOT NULL,
    collaborate_allowed boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    author_user_id uuid NOT NULL,
    folder_id uuid,
    description text,
    source_lab_id uuid,
    topology_locked boolean DEFAULT false NOT NULL,
    topology_allow_wipe boolean DEFAULT false NOT NULL
);


--
-- Name: permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.permissions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    code text NOT NULL,
    title text NOT NULL,
    category text DEFAULT 'general'::text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: role_permissions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.role_permissions (
    role_id uuid NOT NULL,
    permission_id uuid NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: roles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.roles (
    name text NOT NULL,
    id uuid DEFAULT gen_random_uuid() NOT NULL
);


--
-- Name: task_queue_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.task_queue_settings (
    id smallint DEFAULT 1 NOT NULL,
    power_parallel_limit integer DEFAULT 2 NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    worker_slots integer DEFAULT 2 NOT NULL,
    fast_stop_vios boolean DEFAULT true NOT NULL,
    start_worker_slots integer DEFAULT 1 NOT NULL,
    stop_worker_slots integer DEFAULT 1 NOT NULL,
    check_worker_slots integer DEFAULT 1 NOT NULL,
    CONSTRAINT task_queue_settings_check_worker_slots_chk CHECK (((check_worker_slots >= 1) AND (check_worker_slots <= 32))),
    CONSTRAINT task_queue_settings_limit_chk CHECK (((power_parallel_limit >= 1) AND (power_parallel_limit <= 32))),
    CONSTRAINT task_queue_settings_singleton CHECK ((id = 1)),
    CONSTRAINT task_queue_settings_start_worker_slots_chk CHECK (((start_worker_slots >= 1) AND (start_worker_slots <= 32))),
    CONSTRAINT task_queue_settings_stop_worker_slots_chk CHECK (((stop_worker_slots >= 1) AND (stop_worker_slots <= 32))),
    CONSTRAINT task_queue_settings_worker_slots_chk CHECK (((worker_slots >= 1) AND (worker_slots <= 32)))
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    username text NOT NULL,
    last_seen timestamp with time zone,
    last_ip inet,
    is_blocked boolean DEFAULT false NOT NULL,
    password_hash character varying(255) NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    lang character varying(8) DEFAULT 'en'::character varying NOT NULL,
    theme character varying(16) DEFAULT 'dark'::character varying NOT NULL,
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    role_id uuid NOT NULL
);


--
-- Name: auth_sessions auth_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_sessions
    ADD CONSTRAINT auth_sessions_pkey PRIMARY KEY (token);


--
-- Name: cloud_users cloud_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_users
    ADD CONSTRAINT cloud_users_pkey PRIMARY KEY (id);


--
-- Name: clouds clouds_name_pnet_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clouds
    ADD CONSTRAINT clouds_name_pnet_uniq UNIQUE (name, pnet);


--
-- Name: clouds clouds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.clouds
    ADD CONSTRAINT clouds_pkey PRIMARY KEY (id);


--
-- Name: lab_check_grade_scales lab_check_grade_scales_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_grade_scales
    ADD CONSTRAINT lab_check_grade_scales_pkey PRIMARY KEY (id);


--
-- Name: lab_check_items lab_check_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_items
    ADD CONSTRAINT lab_check_items_pkey PRIMARY KEY (id);


--
-- Name: lab_check_run_items lab_check_run_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_run_items
    ADD CONSTRAINT lab_check_run_items_pkey PRIMARY KEY (id);


--
-- Name: lab_check_runs lab_check_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_runs
    ADD CONSTRAINT lab_check_runs_pkey PRIMARY KEY (id);


--
-- Name: lab_check_settings lab_check_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_settings
    ADD CONSTRAINT lab_check_settings_pkey PRIMARY KEY (lab_id);


--
-- Name: lab_check_task_items lab_check_task_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_items
    ADD CONSTRAINT lab_check_task_items_pkey PRIMARY KEY (id);


--
-- Name: lab_check_task_marks lab_check_task_marks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_marks
    ADD CONSTRAINT lab_check_task_marks_pkey PRIMARY KEY (lab_id, task_item_id, user_id);


--
-- Name: lab_check_task_settings lab_check_task_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_settings
    ADD CONSTRAINT lab_check_task_settings_pkey PRIMARY KEY (lab_id);


--
-- Name: lab_collab_tokens lab_collab_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_collab_tokens
    ADD CONSTRAINT lab_collab_tokens_pkey PRIMARY KEY (token);


--
-- Name: lab_folders lab_folders_owner_parent_name_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_folders
    ADD CONSTRAINT lab_folders_owner_parent_name_uniq UNIQUE (owner_user_id, parent_id, name);


--
-- Name: lab_folders lab_folders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_folders
    ADD CONSTRAINT lab_folders_pkey PRIMARY KEY (id);


--
-- Name: lab_networks lab_networks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_networks
    ADD CONSTRAINT lab_networks_pkey PRIMARY KEY (id);


--
-- Name: lab_node_ports lab_node_ports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_node_ports
    ADD CONSTRAINT lab_node_ports_pkey PRIMARY KEY (id);


--
-- Name: lab_nodes lab_nodes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_nodes
    ADD CONSTRAINT lab_nodes_pkey PRIMARY KEY (id);


--
-- Name: lab_objects lab_objects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_objects
    ADD CONSTRAINT lab_objects_pkey PRIMARY KEY (id);


--
-- Name: lab_shared_users lab_shared_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_shared_users
    ADD CONSTRAINT lab_shared_users_pkey PRIMARY KEY (lab_id, user_id);


--
-- Name: lab_task_groups lab_task_groups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_task_groups
    ADD CONSTRAINT lab_task_groups_pkey PRIMARY KEY (id);


--
-- Name: lab_tasks lab_tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_tasks
    ADD CONSTRAINT lab_tasks_pkey PRIMARY KEY (id);


--
-- Name: labs labs_author_folder_name_uniq; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.labs
    ADD CONSTRAINT labs_author_folder_name_uniq UNIQUE (author_user_id, folder_id, name);


--
-- Name: labs labs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.labs
    ADD CONSTRAINT labs_pkey PRIMARY KEY (id);


--
-- Name: permissions permissions_code_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_code_key UNIQUE (code);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: role_permissions role_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_pkey PRIMARY KEY (role_id, permission_id);


--
-- Name: roles roles_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_key UNIQUE (name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: task_queue_settings task_queue_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.task_queue_settings
    ADD CONSTRAINT task_queue_settings_pkey PRIMARY KEY (id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: idx_auth_sessions_ended_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auth_sessions_ended_at ON public.auth_sessions USING btree (ended_at);


--
-- Name: idx_auth_sessions_expires_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auth_sessions_expires_at ON public.auth_sessions USING btree (expires_at);


--
-- Name: idx_auth_sessions_last_activity; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auth_sessions_last_activity ON public.auth_sessions USING btree (last_activity);


--
-- Name: idx_auth_sessions_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_auth_sessions_user_id ON public.auth_sessions USING btree (user_id);


--
-- Name: idx_cloud_users_cloud_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cloud_users_cloud_id ON public.cloud_users USING btree (cloud_id);


--
-- Name: idx_cloud_users_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_cloud_users_user_id ON public.cloud_users USING btree (user_id);


--
-- Name: idx_clouds_pnet; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_clouds_pnet ON public.clouds USING btree (pnet);


--
-- Name: idx_lab_check_grade_scales_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_check_grade_scales_lab_id ON public.lab_check_grade_scales USING btree (lab_id);


--
-- Name: idx_lab_check_items_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_check_items_lab_id ON public.lab_check_items USING btree (lab_id);


--
-- Name: idx_lab_check_items_node_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_check_items_node_id ON public.lab_check_items USING btree (node_id);


--
-- Name: idx_lab_check_run_items_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_check_run_items_lab_id ON public.lab_check_run_items USING btree (lab_id);


--
-- Name: idx_lab_check_run_items_run_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_check_run_items_run_id ON public.lab_check_run_items USING btree (run_id);


--
-- Name: idx_lab_check_runs_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_check_runs_lab_id ON public.lab_check_runs USING btree (lab_id, started_at DESC);


--
-- Name: idx_lab_check_runs_started_by; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_check_runs_started_by ON public.lab_check_runs USING btree (started_by);


--
-- Name: idx_lab_check_task_items_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_check_task_items_lab_id ON public.lab_check_task_items USING btree (lab_id);


--
-- Name: idx_lab_check_task_marks_lab_user; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_check_task_marks_lab_user ON public.lab_check_task_marks USING btree (lab_id, user_id);


--
-- Name: idx_lab_collab_tokens_expires_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_collab_tokens_expires_at ON public.lab_collab_tokens USING btree (expires_at);


--
-- Name: idx_lab_collab_tokens_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_collab_tokens_lab_id ON public.lab_collab_tokens USING btree (lab_id);


--
-- Name: idx_lab_collab_tokens_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_collab_tokens_user_id ON public.lab_collab_tokens USING btree (user_id);


--
-- Name: idx_lab_folders_owner_parent_name_uniq_nested; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_lab_folders_owner_parent_name_uniq_nested ON public.lab_folders USING btree (owner_user_id, parent_id, lower((name)::text)) WHERE (parent_id IS NOT NULL);


--
-- Name: idx_lab_folders_owner_parent_name_uniq_root; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_lab_folders_owner_parent_name_uniq_root ON public.lab_folders USING btree (owner_user_id, lower((name)::text)) WHERE (parent_id IS NULL);


--
-- Name: idx_lab_folders_owner_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_folders_owner_user_id ON public.lab_folders USING btree (owner_user_id);


--
-- Name: idx_lab_folders_parent_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_folders_parent_id ON public.lab_folders USING btree (parent_id);


--
-- Name: idx_lab_networks_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_networks_lab_id ON public.lab_networks USING btree (lab_id);


--
-- Name: idx_lab_networks_network_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_networks_network_type ON public.lab_networks USING btree (network_type);


--
-- Name: idx_lab_node_ports_network_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_node_ports_network_id ON public.lab_node_ports USING btree (network_id);


--
-- Name: idx_lab_node_ports_node_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_node_ports_node_id ON public.lab_node_ports USING btree (node_id);


--
-- Name: idx_lab_nodes_is_running; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_nodes_is_running ON public.lab_nodes USING btree (is_running);


--
-- Name: idx_lab_nodes_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_nodes_lab_id ON public.lab_nodes USING btree (lab_id);


--
-- Name: idx_lab_nodes_node_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_nodes_node_type ON public.lab_nodes USING btree (node_type);


--
-- Name: idx_lab_nodes_power_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_nodes_power_state ON public.lab_nodes USING btree (power_state);


--
-- Name: idx_lab_nodes_power_updated_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_nodes_power_updated_at ON public.lab_nodes USING btree (power_updated_at DESC);


--
-- Name: idx_lab_nodes_runtime_pid; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_nodes_runtime_pid ON public.lab_nodes USING btree (runtime_pid) WHERE (runtime_pid IS NOT NULL);


--
-- Name: idx_lab_nodes_runtime_started_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_nodes_runtime_started_at ON public.lab_nodes USING btree (runtime_started_at DESC);


--
-- Name: idx_lab_objects_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_objects_lab_id ON public.lab_objects USING btree (lab_id);


--
-- Name: idx_lab_objects_object_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_objects_object_type ON public.lab_objects USING btree (object_type);


--
-- Name: idx_lab_shared_users_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_shared_users_user_id ON public.lab_shared_users USING btree (user_id);


--
-- Name: idx_lab_task_groups_lab_id_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_task_groups_lab_id_created_at ON public.lab_task_groups USING btree (lab_id, created_at DESC);


--
-- Name: idx_lab_task_groups_status_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_task_groups_status_created_at ON public.lab_task_groups USING btree (status, created_at DESC);


--
-- Name: idx_lab_tasks_action_status_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_tasks_action_status_created_at ON public.lab_tasks USING btree (action, status, created_at);


--
-- Name: idx_lab_tasks_lab_check_active_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_lab_tasks_lab_check_active_unique ON public.lab_tasks USING btree (lab_id) WHERE (((action)::text = 'lab_check'::text) AND ((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying])::text[])));


--
-- Name: idx_lab_tasks_lab_id_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_tasks_lab_id_created_at ON public.lab_tasks USING btree (lab_id, created_at DESC);


--
-- Name: idx_lab_tasks_node_id_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_tasks_node_id_created_at ON public.lab_tasks USING btree (node_id, created_at DESC);


--
-- Name: idx_lab_tasks_status_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_lab_tasks_status_created_at ON public.lab_tasks USING btree (status, created_at);


--
-- Name: idx_labs_author_folder_name_uniq_nested; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_labs_author_folder_name_uniq_nested ON public.labs USING btree (author_user_id, folder_id, lower((name)::text)) WHERE (folder_id IS NOT NULL);


--
-- Name: idx_labs_author_folder_name_uniq_root; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_labs_author_folder_name_uniq_root ON public.labs USING btree (author_user_id, lower((name)::text)) WHERE (folder_id IS NULL);


--
-- Name: idx_labs_author_source_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_labs_author_source_unique ON public.labs USING btree (author_user_id, source_lab_id) WHERE (source_lab_id IS NOT NULL);


--
-- Name: idx_labs_author_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_labs_author_user_id ON public.labs USING btree (author_user_id);


--
-- Name: idx_labs_folder_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_labs_folder_id ON public.labs USING btree (folder_id);


--
-- Name: idx_labs_is_shared; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_labs_is_shared ON public.labs USING btree (is_shared);


--
-- Name: idx_labs_source_lab_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_labs_source_lab_id ON public.labs USING btree (source_lab_id);


--
-- Name: idx_permissions_category; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_permissions_category ON public.permissions USING btree (category);


--
-- Name: idx_role_permissions_permission_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_role_permissions_permission_id ON public.role_permissions USING btree (permission_id);


--
-- Name: idx_role_permissions_role_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_role_permissions_role_id ON public.role_permissions USING btree (role_id);


--
-- Name: idx_users_role_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_users_role_id ON public.users USING btree (role_id);


--
-- Name: lab_folders trg_lab_folders_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_folders_set_updated_at BEFORE UPDATE ON public.lab_folders FOR EACH ROW EXECUTE FUNCTION public.set_lab_folders_updated_at();


--
-- Name: lab_folders trg_lab_folders_touch_parent; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_folders_touch_parent AFTER INSERT OR DELETE OR UPDATE ON public.lab_folders FOR EACH ROW EXECUTE FUNCTION public.trg_lab_folders_touch_parent();


--
-- Name: lab_networks trg_lab_networks_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_networks_set_updated_at BEFORE UPDATE ON public.lab_networks FOR EACH ROW EXECUTE FUNCTION public.set_lab_networks_updated_at();


--
-- Name: lab_networks trg_lab_networks_touch_labs_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_networks_touch_labs_updated AFTER INSERT OR DELETE OR UPDATE ON public.lab_networks FOR EACH ROW EXECUTE FUNCTION public.trg_touch_labs_updated_from_activity();


--
-- Name: lab_node_ports trg_lab_node_ports_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_node_ports_set_updated_at BEFORE UPDATE ON public.lab_node_ports FOR EACH ROW EXECUTE FUNCTION public.set_lab_node_ports_updated_at();


--
-- Name: lab_node_ports trg_lab_node_ports_touch_labs_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_node_ports_touch_labs_updated AFTER INSERT OR DELETE OR UPDATE ON public.lab_node_ports FOR EACH ROW EXECUTE FUNCTION public.trg_touch_labs_updated_from_ports();


--
-- Name: lab_nodes trg_lab_nodes_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_nodes_set_updated_at BEFORE UPDATE ON public.lab_nodes FOR EACH ROW EXECUTE FUNCTION public.set_lab_nodes_updated_at();


--
-- Name: lab_nodes trg_lab_nodes_touch_labs_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_nodes_touch_labs_updated AFTER INSERT OR DELETE OR UPDATE ON public.lab_nodes FOR EACH ROW EXECUTE FUNCTION public.trg_touch_labs_updated_from_activity();


--
-- Name: lab_objects trg_lab_objects_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_objects_set_updated_at BEFORE UPDATE ON public.lab_objects FOR EACH ROW EXECUTE FUNCTION public.set_lab_objects_updated_at();


--
-- Name: lab_objects trg_lab_objects_touch_labs_updated; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_objects_touch_labs_updated AFTER INSERT OR DELETE OR UPDATE ON public.lab_objects FOR EACH ROW EXECUTE FUNCTION public.trg_touch_labs_updated_from_activity();


--
-- Name: lab_task_groups trg_lab_task_groups_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_task_groups_set_updated_at BEFORE UPDATE ON public.lab_task_groups FOR EACH ROW EXECUTE FUNCTION public.set_lab_task_groups_updated_at();


--
-- Name: lab_tasks trg_lab_tasks_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_lab_tasks_set_updated_at BEFORE UPDATE ON public.lab_tasks FOR EACH ROW EXECUTE FUNCTION public.set_lab_tasks_updated_at();


--
-- Name: labs trg_labs_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_labs_set_updated_at BEFORE UPDATE ON public.labs FOR EACH ROW EXECUTE FUNCTION public.set_labs_updated_at();


--
-- Name: labs trg_labs_touch_folder_chain; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_labs_touch_folder_chain AFTER INSERT OR DELETE OR UPDATE ON public.labs FOR EACH ROW EXECUTE FUNCTION public.trg_labs_touch_folder_chain();


--
-- Name: users trg_users_set_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_users_set_updated_at BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.set_users_updated_at();


--
-- Name: auth_sessions auth_sessions_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_sessions
    ADD CONSTRAINT auth_sessions_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: cloud_users cloud_users_cloud_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_users
    ADD CONSTRAINT cloud_users_cloud_id_fkey FOREIGN KEY (cloud_id) REFERENCES public.clouds(id) ON DELETE CASCADE;


--
-- Name: cloud_users cloud_users_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cloud_users
    ADD CONSTRAINT cloud_users_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: lab_check_grade_scales lab_check_grade_scales_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_grade_scales
    ADD CONSTRAINT lab_check_grade_scales_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_check_items lab_check_items_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_items
    ADD CONSTRAINT lab_check_items_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: lab_check_items lab_check_items_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_items
    ADD CONSTRAINT lab_check_items_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_check_items lab_check_items_node_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_items
    ADD CONSTRAINT lab_check_items_node_id_fkey FOREIGN KEY (node_id) REFERENCES public.lab_nodes(id) ON DELETE CASCADE;


--
-- Name: lab_check_items lab_check_items_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_items
    ADD CONSTRAINT lab_check_items_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: lab_check_run_items lab_check_run_items_check_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_run_items
    ADD CONSTRAINT lab_check_run_items_check_item_id_fkey FOREIGN KEY (check_item_id) REFERENCES public.lab_check_items(id) ON DELETE SET NULL;


--
-- Name: lab_check_run_items lab_check_run_items_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_run_items
    ADD CONSTRAINT lab_check_run_items_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_check_run_items lab_check_run_items_node_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_run_items
    ADD CONSTRAINT lab_check_run_items_node_id_fkey FOREIGN KEY (node_id) REFERENCES public.lab_nodes(id) ON DELETE SET NULL;


--
-- Name: lab_check_run_items lab_check_run_items_run_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_run_items
    ADD CONSTRAINT lab_check_run_items_run_id_fkey FOREIGN KEY (run_id) REFERENCES public.lab_check_runs(id) ON DELETE CASCADE;


--
-- Name: lab_check_runs lab_check_runs_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_runs
    ADD CONSTRAINT lab_check_runs_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_check_runs lab_check_runs_started_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_runs
    ADD CONSTRAINT lab_check_runs_started_by_fkey FOREIGN KEY (started_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: lab_check_settings lab_check_settings_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_settings
    ADD CONSTRAINT lab_check_settings_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_check_settings lab_check_settings_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_settings
    ADD CONSTRAINT lab_check_settings_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: lab_check_task_items lab_check_task_items_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_items
    ADD CONSTRAINT lab_check_task_items_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: lab_check_task_items lab_check_task_items_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_items
    ADD CONSTRAINT lab_check_task_items_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_check_task_items lab_check_task_items_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_items
    ADD CONSTRAINT lab_check_task_items_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: lab_check_task_marks lab_check_task_marks_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_marks
    ADD CONSTRAINT lab_check_task_marks_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_check_task_marks lab_check_task_marks_task_item_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_marks
    ADD CONSTRAINT lab_check_task_marks_task_item_id_fkey FOREIGN KEY (task_item_id) REFERENCES public.lab_check_task_items(id) ON DELETE CASCADE;


--
-- Name: lab_check_task_marks lab_check_task_marks_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_marks
    ADD CONSTRAINT lab_check_task_marks_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: lab_check_task_settings lab_check_task_settings_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_settings
    ADD CONSTRAINT lab_check_task_settings_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_check_task_settings lab_check_task_settings_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_check_task_settings
    ADD CONSTRAINT lab_check_task_settings_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: lab_collab_tokens lab_collab_tokens_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_collab_tokens
    ADD CONSTRAINT lab_collab_tokens_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_collab_tokens lab_collab_tokens_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_collab_tokens
    ADD CONSTRAINT lab_collab_tokens_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: lab_folders lab_folders_owner_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_folders
    ADD CONSTRAINT lab_folders_owner_user_id_fkey FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: lab_folders lab_folders_parent_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_folders
    ADD CONSTRAINT lab_folders_parent_id_fkey FOREIGN KEY (parent_id) REFERENCES public.lab_folders(id) ON DELETE CASCADE;


--
-- Name: lab_networks lab_networks_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_networks
    ADD CONSTRAINT lab_networks_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_node_ports lab_node_ports_network_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_node_ports
    ADD CONSTRAINT lab_node_ports_network_id_fkey FOREIGN KEY (network_id) REFERENCES public.lab_networks(id) ON DELETE SET NULL;


--
-- Name: lab_node_ports lab_node_ports_node_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_node_ports
    ADD CONSTRAINT lab_node_ports_node_id_fkey FOREIGN KEY (node_id) REFERENCES public.lab_nodes(id) ON DELETE CASCADE;


--
-- Name: lab_nodes lab_nodes_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_nodes
    ADD CONSTRAINT lab_nodes_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_objects lab_objects_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_objects
    ADD CONSTRAINT lab_objects_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_shared_users lab_shared_users_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_shared_users
    ADD CONSTRAINT lab_shared_users_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_shared_users lab_shared_users_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_shared_users
    ADD CONSTRAINT lab_shared_users_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: lab_task_groups lab_task_groups_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_task_groups
    ADD CONSTRAINT lab_task_groups_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_task_groups lab_task_groups_requested_by_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_task_groups
    ADD CONSTRAINT lab_task_groups_requested_by_user_id_fkey FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: lab_tasks lab_tasks_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_tasks
    ADD CONSTRAINT lab_tasks_lab_id_fkey FOREIGN KEY (lab_id) REFERENCES public.labs(id) ON DELETE CASCADE;


--
-- Name: lab_tasks lab_tasks_node_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_tasks
    ADD CONSTRAINT lab_tasks_node_id_fkey FOREIGN KEY (node_id) REFERENCES public.lab_nodes(id) ON DELETE CASCADE;


--
-- Name: lab_tasks lab_tasks_requested_by_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.lab_tasks
    ADD CONSTRAINT lab_tasks_requested_by_user_id_fkey FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: labs labs_author_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.labs
    ADD CONSTRAINT labs_author_user_id_fkey FOREIGN KEY (author_user_id) REFERENCES public.users(id);


--
-- Name: labs labs_folder_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.labs
    ADD CONSTRAINT labs_folder_id_fkey FOREIGN KEY (folder_id) REFERENCES public.lab_folders(id) ON DELETE CASCADE;


--
-- Name: labs labs_source_lab_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.labs
    ADD CONSTRAINT labs_source_lab_id_fkey FOREIGN KEY (source_lab_id) REFERENCES public.labs(id) ON DELETE SET NULL;


--
-- Name: role_permissions role_permissions_permission_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_permissions role_permissions_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.role_permissions
    ADD CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: users users_role_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_role_id_fkey FOREIGN KEY (role_id) REFERENCES public.roles(id);


--
-- PostgreSQL database dump complete
--

