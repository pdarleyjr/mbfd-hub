--
-- PostgreSQL database dump
--

\restrict QDlDXD6GJ7oCdgiBWSWcXPhOyDf4IfYflDQOcgPwkEvh7mPWJFvVxge71BH2cAq

-- Dumped from database version 18.1
-- Dumped by pg_dump version 18.1

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admin_alert_events; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.admin_alert_events (
    id bigint NOT NULL,
    type character varying(255) NOT NULL,
    severity character varying(255) DEFAULT 'info'::character varying NOT NULL,
    message text NOT NULL,
    related_type character varying(255),
    related_id bigint,
    is_read boolean DEFAULT false NOT NULL,
    created_by_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.admin_alert_events OWNER TO mbfd_user;

--
-- Name: COLUMN admin_alert_events.type; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.admin_alert_events.type IS 'defect_created|recommendation_created|low_stock|allocation_made';


--
-- Name: COLUMN admin_alert_events.severity; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.admin_alert_events.severity IS 'info|warning|critical';


--
-- Name: COLUMN admin_alert_events.related_type; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.admin_alert_events.related_type IS 'Polymorphic type: apparatus_defect, equipment_item, etc.';


--
-- Name: COLUMN admin_alert_events.related_id; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.admin_alert_events.related_id IS 'Polymorphic ID';


--
-- Name: admin_alert_events_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.admin_alert_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_alert_events_id_seq OWNER TO mbfd_user;

--
-- Name: admin_alert_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.admin_alert_events_id_seq OWNED BY public.admin_alert_events.id;


--
-- Name: ai_analysis_logs; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.ai_analysis_logs (
    id bigint NOT NULL,
    type character varying(255) NOT NULL,
    projects_analyzed integer NOT NULL,
    result json NOT NULL,
    executed_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.ai_analysis_logs OWNER TO mbfd_user;

--
-- Name: ai_analysis_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.ai_analysis_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ai_analysis_logs_id_seq OWNER TO mbfd_user;

--
-- Name: ai_analysis_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.ai_analysis_logs_id_seq OWNED BY public.ai_analysis_logs.id;


--
-- Name: apparatus_defect_recommendations; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.apparatus_defect_recommendations (
    id bigint NOT NULL,
    apparatus_defect_id bigint NOT NULL,
    equipment_item_id bigint,
    match_method character varying(255) NOT NULL,
    match_confidence numeric(5,4) DEFAULT '0'::numeric NOT NULL,
    recommended_qty integer DEFAULT 1 NOT NULL,
    reasoning text NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    created_by_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.apparatus_defect_recommendations OWNER TO mbfd_user;

--
-- Name: COLUMN apparatus_defect_recommendations.match_method; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.apparatus_defect_recommendations.match_method IS 'exact|trigram|fuzzy|ai|manual';


--
-- Name: COLUMN apparatus_defect_recommendations.match_confidence; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.apparatus_defect_recommendations.match_confidence IS '0.0000 to 1.0000';


--
-- Name: COLUMN apparatus_defect_recommendations.reasoning; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.apparatus_defect_recommendations.reasoning IS 'Why this item was recommended';


--
-- Name: COLUMN apparatus_defect_recommendations.status; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.apparatus_defect_recommendations.status IS 'pending|allocated|dismissed';


--
-- Name: apparatus_defect_recommendations_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.apparatus_defect_recommendations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.apparatus_defect_recommendations_id_seq OWNER TO mbfd_user;

--
-- Name: apparatus_defect_recommendations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.apparatus_defect_recommendations_id_seq OWNED BY public.apparatus_defect_recommendations.id;


--
-- Name: apparatus_defects; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.apparatus_defects (
    id bigint NOT NULL,
    apparatus_id bigint NOT NULL,
    compartment character varying(255) NOT NULL,
    item character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    notes text,
    photo text,
    resolved boolean DEFAULT false NOT NULL,
    resolved_at timestamp(0) without time zone,
    resolution_notes text,
    defect_history jsonb,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    apparatus_inspection_id bigint,
    issue_type character varying(255) DEFAULT 'missing'::character varying NOT NULL,
    reported_date date,
    photo_path character varying(255)
);


ALTER TABLE public.apparatus_defects OWNER TO mbfd_user;

--
-- Name: apparatus_defects_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.apparatus_defects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.apparatus_defects_id_seq OWNER TO mbfd_user;

--
-- Name: apparatus_defects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.apparatus_defects_id_seq OWNED BY public.apparatus_defects.id;


--
-- Name: apparatus_inspections; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.apparatus_inspections (
    id bigint NOT NULL,
    apparatus_id bigint NOT NULL,
    operator_name character varying(255) NOT NULL,
    rank character varying(255) NOT NULL,
    shift character varying(255) NOT NULL,
    unit_number character varying(255),
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.apparatus_inspections OWNER TO mbfd_user;

--
-- Name: apparatus_inspections_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.apparatus_inspections_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.apparatus_inspections_id_seq OWNER TO mbfd_user;

--
-- Name: apparatus_inspections_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.apparatus_inspections_id_seq OWNED BY public.apparatus_inspections.id;


--
-- Name: apparatus_inventory_allocations; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.apparatus_inventory_allocations (
    id bigint NOT NULL,
    apparatus_id bigint NOT NULL,
    apparatus_defect_id bigint NOT NULL,
    equipment_item_id bigint NOT NULL,
    qty_allocated integer NOT NULL,
    allocated_by_user_id bigint NOT NULL,
    allocated_at timestamp(0) without time zone NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.apparatus_inventory_allocations OWNER TO mbfd_user;

--
-- Name: apparatus_inventory_allocations_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.apparatus_inventory_allocations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.apparatus_inventory_allocations_id_seq OWNER TO mbfd_user;

--
-- Name: apparatus_inventory_allocations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.apparatus_inventory_allocations_id_seq OWNED BY public.apparatus_inventory_allocations.id;


--
-- Name: apparatuses; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.apparatuses (
    id bigint NOT NULL,
    unit_id character varying(255),
    vin character varying(255),
    make character varying(255),
    model character varying(255),
    year integer,
    status character varying(255) DEFAULT 'In Service'::character varying NOT NULL,
    mileage integer DEFAULT 0,
    last_service_date date,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    name character varying(255),
    type character varying(255),
    vehicle_number character varying(255),
    slug character varying(255),
    designation character varying(255),
    assignment character varying(255),
    current_location character varying(255),
    class_description character varying(255)
);


ALTER TABLE public.apparatuses OWNER TO mbfd_user;

--
-- Name: apparatuses_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.apparatuses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.apparatuses_id_seq OWNER TO mbfd_user;

--
-- Name: apparatuses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.apparatuses_id_seq OWNED BY public.apparatuses.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache OWNER TO mbfd_user;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO mbfd_user;

--
-- Name: capital_projects; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.capital_projects (
    id bigint NOT NULL,
    name character varying(255) CONSTRAINT capital_projects_project_name_not_null NOT NULL,
    description text,
    budget_amount numeric(12,2) DEFAULT '0'::numeric CONSTRAINT capital_projects_budget_not_null NOT NULL,
    start_date date,
    target_completion_date date,
    actual_completion date,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    project_number character varying(255) NOT NULL,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    ai_priority_rank integer,
    ai_priority_score integer,
    ai_reasoning text,
    last_ai_analysis timestamp(0) without time zone,
    CONSTRAINT capital_projects_priority_check CHECK (((priority)::text = ANY ((ARRAY['low'::character varying, 'medium'::character varying, 'high'::character varying, 'critical'::character varying])::text[]))),
    CONSTRAINT capital_projects_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'in-progress'::character varying, 'completed'::character varying, 'on-hold'::character varying])::text[])))
);


ALTER TABLE public.capital_projects OWNER TO mbfd_user;

--
-- Name: capital_projects_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.capital_projects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.capital_projects_id_seq OWNER TO mbfd_user;

--
-- Name: capital_projects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.capital_projects_id_seq OWNED BY public.capital_projects.id;


--
-- Name: equipment_items; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.equipment_items (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    normalized_name character varying(255) NOT NULL,
    category character varying(255),
    description text,
    manufacturer character varying(255),
    unit_of_measure character varying(255),
    reorder_min integer DEFAULT 0 NOT NULL,
    reorder_max integer,
    location_id bigint,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.equipment_items OWNER TO mbfd_user;

--
-- Name: COLUMN equipment_items.name; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.equipment_items.name IS 'Display name';


--
-- Name: COLUMN equipment_items.normalized_name; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.equipment_items.normalized_name IS 'Lowercased, trimmed, no punctuation for matching';


--
-- Name: COLUMN equipment_items.category; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.equipment_items.category IS 'Equipment category';


--
-- Name: COLUMN equipment_items.unit_of_measure; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.equipment_items.unit_of_measure IS 'each, box, case, etc.';


--
-- Name: COLUMN equipment_items.reorder_min; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.equipment_items.reorder_min IS 'Low stock threshold';


--
-- Name: COLUMN equipment_items.reorder_max; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.equipment_items.reorder_max IS 'Par level / target stock';


--
-- Name: equipment_items_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.equipment_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.equipment_items_id_seq OWNER TO mbfd_user;

--
-- Name: equipment_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.equipment_items_id_seq OWNED BY public.equipment_items.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO mbfd_user;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO mbfd_user;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: import_runs; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.import_runs (
    id bigint NOT NULL,
    type character varying(255) NOT NULL,
    file_path character varying(255) NOT NULL,
    rows_processed integer DEFAULT 0 NOT NULL,
    items_created integer DEFAULT 0 NOT NULL,
    items_updated integer DEFAULT 0 NOT NULL,
    metadata json,
    user_id bigint,
    started_at timestamp(0) without time zone NOT NULL,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.import_runs OWNER TO mbfd_user;

--
-- Name: COLUMN import_runs.type; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.import_runs.type IS 'fire_equipment|uniforms|etc';


--
-- Name: COLUMN import_runs.metadata; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.import_runs.metadata IS 'Warnings, stats, etc';


--
-- Name: import_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.import_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.import_runs_id_seq OWNER TO mbfd_user;

--
-- Name: import_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.import_runs_id_seq OWNED BY public.import_runs.id;


--
-- Name: inventory_locations; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.inventory_locations (
    id bigint NOT NULL,
    location_name character varying(255) NOT NULL,
    shelf character(1),
    "row" integer,
    bin character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.inventory_locations OWNER TO mbfd_user;

--
-- Name: COLUMN inventory_locations.location_name; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.inventory_locations.location_name IS 'e.g., Supply Closet';


--
-- Name: COLUMN inventory_locations.shelf; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.inventory_locations.shelf IS 'A-F';


--
-- Name: COLUMN inventory_locations."row"; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.inventory_locations."row" IS '1-N';


--
-- Name: COLUMN inventory_locations.bin; Type: COMMENT; Schema: public; Owner: mbfd_user
--

COMMENT ON COLUMN public.inventory_locations.bin IS 'Optional bin identifier';


--
-- Name: inventory_locations_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.inventory_locations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inventory_locations_id_seq OWNER TO mbfd_user;

--
-- Name: inventory_locations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.inventory_locations_id_seq OWNED BY public.inventory_locations.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO mbfd_user;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO mbfd_user;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO mbfd_user;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO mbfd_user;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO mbfd_user;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


ALTER TABLE public.model_has_permissions OWNER TO mbfd_user;

--
-- Name: model_has_roles; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


ALTER TABLE public.model_has_roles OWNER TO mbfd_user;

--
-- Name: notification_tracking; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.notification_tracking (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    project_id bigint NOT NULL,
    notification_type character varying(255) NOT NULL,
    sent_at timestamp(0) without time zone NOT NULL,
    read_at timestamp(0) without time zone,
    actioned_at timestamp(0) without time zone,
    action_taken character varying(255),
    snoozed_until timestamp(0) without time zone,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.notification_tracking OWNER TO mbfd_user;

--
-- Name: notification_tracking_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.notification_tracking_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.notification_tracking_id_seq OWNER TO mbfd_user;

--
-- Name: notification_tracking_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.notification_tracking_id_seq OWNED BY public.notification_tracking.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.notifications (
    id uuid NOT NULL,
    type character varying(255) NOT NULL,
    notifiable_type character varying(255) NOT NULL,
    notifiable_id bigint NOT NULL,
    data jsonb NOT NULL,
    read_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.notifications OWNER TO mbfd_user;

--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO mbfd_user;

--
-- Name: permissions; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.permissions OWNER TO mbfd_user;

--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.permissions_id_seq OWNER TO mbfd_user;

--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id bigint NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.personal_access_tokens OWNER TO mbfd_user;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.personal_access_tokens_id_seq OWNER TO mbfd_user;

--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: project_milestones; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.project_milestones (
    id bigint NOT NULL,
    capital_project_id bigint NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    due_date date NOT NULL,
    completed boolean DEFAULT false NOT NULL,
    completed_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.project_milestones OWNER TO mbfd_user;

--
-- Name: project_milestones_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.project_milestones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.project_milestones_id_seq OWNER TO mbfd_user;

--
-- Name: project_milestones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.project_milestones_id_seq OWNED BY public.project_milestones.id;


--
-- Name: project_updates; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.project_updates (
    id bigint NOT NULL,
    capital_project_id bigint NOT NULL,
    user_id bigint NOT NULL,
    update_text text NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.project_updates OWNER TO mbfd_user;

--
-- Name: project_updates_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.project_updates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.project_updates_id_seq OWNER TO mbfd_user;

--
-- Name: project_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.project_updates_id_seq OWNED BY public.project_updates.id;


--
-- Name: push_subscriptions; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.push_subscriptions (
    id bigint NOT NULL,
    subscribable_type character varying(255) NOT NULL,
    subscribable_id bigint NOT NULL,
    endpoint character varying(500) NOT NULL,
    public_key character varying(255),
    auth_token character varying(255),
    content_encoding character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.push_subscriptions OWNER TO mbfd_user;

--
-- Name: push_subscriptions_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.push_subscriptions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.push_subscriptions_id_seq OWNER TO mbfd_user;

--
-- Name: push_subscriptions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.push_subscriptions_id_seq OWNED BY public.push_subscriptions.id;


--
-- Name: role_has_permissions; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


ALTER TABLE public.role_has_permissions OWNER TO mbfd_user;

--
-- Name: roles; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.roles OWNER TO mbfd_user;

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.roles_id_seq OWNER TO mbfd_user;

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO mbfd_user;

--
-- Name: shop_works; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.shop_works (
    id bigint NOT NULL,
    project_name character varying(255) NOT NULL,
    description text,
    apparatus_id bigint,
    status character varying(255) DEFAULT 'Pending'::character varying NOT NULL,
    parts_list text,
    estimated_cost numeric(10,2),
    actual_cost numeric(10,2),
    started_date date,
    completed_date date,
    assigned_to character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    priority integer DEFAULT 5 NOT NULL,
    category character varying(255),
    quantity integer DEFAULT 1 NOT NULL,
    CONSTRAINT shop_works_status_check CHECK (((status)::text = ANY ((ARRAY['Pending'::character varying, 'In Progress'::character varying, 'Waiting for Parts'::character varying, 'Completed'::character varying, 'Cancelled'::character varying])::text[])))
);


ALTER TABLE public.shop_works OWNER TO mbfd_user;

--
-- Name: shop_works_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.shop_works_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.shop_works_id_seq OWNER TO mbfd_user;

--
-- Name: shop_works_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.shop_works_id_seq OWNED BY public.shop_works.id;


--
-- Name: stations; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.stations (
    id bigint NOT NULL,
    station_number character varying(255) NOT NULL,
    address character varying(255) NOT NULL,
    city character varying(255) DEFAULT 'Miami Beach'::character varying NOT NULL,
    state character varying(255) DEFAULT 'FL'::character varying NOT NULL,
    zip_code character varying(255) NOT NULL,
    captain_in_charge character varying(255),
    phone character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.stations OWNER TO mbfd_user;

--
-- Name: stations_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.stations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.stations_id_seq OWNER TO mbfd_user;

--
-- Name: stations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.stations_id_seq OWNED BY public.stations.id;


--
-- Name: stock_mutations; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.stock_mutations (
    id bigint NOT NULL,
    stockable_type character varying(255) CONSTRAINT stock_mutations_stocker_type_not_null NOT NULL,
    stockable_id bigint CONSTRAINT stock_mutations_stocker_id_not_null NOT NULL,
    reference character varying(255),
    amount integer NOT NULL,
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.stock_mutations OWNER TO mbfd_user;

--
-- Name: stock_mutations_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.stock_mutations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.stock_mutations_id_seq OWNER TO mbfd_user;

--
-- Name: stock_mutations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.stock_mutations_id_seq OWNED BY public.stock_mutations.id;


--
-- Name: tasks; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.tasks (
    id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.tasks OWNER TO mbfd_user;

--
-- Name: tasks_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.tasks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.tasks_id_seq OWNER TO mbfd_user;

--
-- Name: tasks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.tasks_id_seq OWNED BY public.tasks.id;


--
-- Name: todo_updates; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.todo_updates (
    id bigint NOT NULL,
    todo_id bigint NOT NULL,
    user_id bigint,
    username character varying(255) NOT NULL,
    comment text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.todo_updates OWNER TO mbfd_user;

--
-- Name: todo_updates_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.todo_updates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.todo_updates_id_seq OWNER TO mbfd_user;

--
-- Name: todo_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.todo_updates_id_seq OWNED BY public.todo_updates.id;


--
-- Name: todos; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.todos (
    id bigint NOT NULL,
    sort integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    title character varying(255) NOT NULL,
    description text,
    is_completed boolean DEFAULT false NOT NULL,
    assigned_to_user_id bigint,
    due_at timestamp(0) without time zone,
    assigned_to json,
    created_by character varying(255),
    attachments json,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    assigned_by character varying(255),
    completed_at timestamp(0) without time zone,
    created_by_user_id bigint
);


ALTER TABLE public.todos OWNER TO mbfd_user;

--
-- Name: todos_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.todos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.todos_id_seq OWNER TO mbfd_user;

--
-- Name: todos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.todos_id_seq OWNED BY public.todos.id;


--
-- Name: uniforms; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.uniforms (
    id bigint NOT NULL,
    item_name character varying(255) NOT NULL,
    size character varying(255),
    quantity_on_hand integer DEFAULT 0 NOT NULL,
    reorder_level integer DEFAULT 10 NOT NULL,
    unit_cost numeric(10,2),
    supplier character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT uniforms_size_check CHECK (((size)::text = ANY ((ARRAY['XS'::character varying, 'S'::character varying, 'M'::character varying, 'L'::character varying, 'XL'::character varying, 'XXL'::character varying, 'XXXL'::character varying])::text[])))
);


ALTER TABLE public.uniforms OWNER TO mbfd_user;

--
-- Name: uniforms_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.uniforms_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.uniforms_id_seq OWNER TO mbfd_user;

--
-- Name: uniforms_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.uniforms_id_seq OWNED BY public.uniforms.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    display_name character varying(255),
    rank character varying(255),
    station character varying(255),
    phone character varying(255),
    must_change_password boolean DEFAULT false NOT NULL
);


ALTER TABLE public.users OWNER TO mbfd_user;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO mbfd_user;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: admin_alert_events id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.admin_alert_events ALTER COLUMN id SET DEFAULT nextval('public.admin_alert_events_id_seq'::regclass);


--
-- Name: ai_analysis_logs id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.ai_analysis_logs ALTER COLUMN id SET DEFAULT nextval('public.ai_analysis_logs_id_seq'::regclass);


--
-- Name: apparatus_defect_recommendations id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defect_recommendations ALTER COLUMN id SET DEFAULT nextval('public.apparatus_defect_recommendations_id_seq'::regclass);


--
-- Name: apparatus_defects id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defects ALTER COLUMN id SET DEFAULT nextval('public.apparatus_defects_id_seq'::regclass);


--
-- Name: apparatus_inspections id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_inspections ALTER COLUMN id SET DEFAULT nextval('public.apparatus_inspections_id_seq'::regclass);


--
-- Name: apparatus_inventory_allocations id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_inventory_allocations ALTER COLUMN id SET DEFAULT nextval('public.apparatus_inventory_allocations_id_seq'::regclass);


--
-- Name: apparatuses id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatuses ALTER COLUMN id SET DEFAULT nextval('public.apparatuses_id_seq'::regclass);


--
-- Name: capital_projects id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.capital_projects ALTER COLUMN id SET DEFAULT nextval('public.capital_projects_id_seq'::regclass);


--
-- Name: equipment_items id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.equipment_items ALTER COLUMN id SET DEFAULT nextval('public.equipment_items_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: import_runs id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.import_runs ALTER COLUMN id SET DEFAULT nextval('public.import_runs_id_seq'::regclass);


--
-- Name: inventory_locations id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.inventory_locations ALTER COLUMN id SET DEFAULT nextval('public.inventory_locations_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: notification_tracking id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.notification_tracking ALTER COLUMN id SET DEFAULT nextval('public.notification_tracking_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: project_milestones id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.project_milestones ALTER COLUMN id SET DEFAULT nextval('public.project_milestones_id_seq'::regclass);


--
-- Name: project_updates id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.project_updates ALTER COLUMN id SET DEFAULT nextval('public.project_updates_id_seq'::regclass);


--
-- Name: push_subscriptions id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.push_subscriptions ALTER COLUMN id SET DEFAULT nextval('public.push_subscriptions_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: shop_works id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.shop_works ALTER COLUMN id SET DEFAULT nextval('public.shop_works_id_seq'::regclass);


--
-- Name: stations id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.stations ALTER COLUMN id SET DEFAULT nextval('public.stations_id_seq'::regclass);


--
-- Name: stock_mutations id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.stock_mutations ALTER COLUMN id SET DEFAULT nextval('public.stock_mutations_id_seq'::regclass);


--
-- Name: tasks id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.tasks ALTER COLUMN id SET DEFAULT nextval('public.tasks_id_seq'::regclass);


--
-- Name: todo_updates id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.todo_updates ALTER COLUMN id SET DEFAULT nextval('public.todo_updates_id_seq'::regclass);


--
-- Name: todos id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.todos ALTER COLUMN id SET DEFAULT nextval('public.todos_id_seq'::regclass);


--
-- Name: uniforms id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.uniforms ALTER COLUMN id SET DEFAULT nextval('public.uniforms_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Data for Name: admin_alert_events; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.admin_alert_events (id, type, severity, message, related_type, related_id, is_read, created_by_user_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: ai_analysis_logs; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.ai_analysis_logs (id, type, projects_analyzed, result, executed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: apparatus_defect_recommendations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.apparatus_defect_recommendations (id, apparatus_defect_id, equipment_item_id, match_method, match_confidence, recommended_qty, reasoning, status, created_by_user_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: apparatus_defects; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.apparatus_defects (id, apparatus_id, compartment, item, status, notes, photo, resolved, resolved_at, resolution_notes, defect_history, created_at, updated_at, apparatus_inspection_id, issue_type, reported_date, photo_path) FROM stdin;
\.


--
-- Data for Name: apparatus_inspections; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.apparatus_inspections (id, apparatus_id, operator_name, rank, shift, unit_number, completed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: apparatus_inventory_allocations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.apparatus_inventory_allocations (id, apparatus_id, apparatus_defect_id, equipment_item_id, qty_allocated, allocated_by_user_id, allocated_at, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: apparatuses; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.apparatuses (id, unit_id, vin, make, model, year, status, mileage, last_service_date, notes, created_at, updated_at, name, type, vehicle_number, slug, designation, assignment, current_location, class_description) FROM stdin;
42	A1	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	AIR TRUCK A1	Air Truck	002-20	a1	A 1	Station 2	Station 2	AIR TRUCK
43	A2	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	AIR TRUCK A2	Air Truck	18500	a2	A 2	Station 2	Station 2	AIR TRUCK
44	E2	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E2	Engine	24509	e2	E 2	Station 2	Station 2	ENGINE
45	R2	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R2	Rescue	16507	r2	R 2	Station 2	Station 2	RESCUE
53	E11	\N	\N	\N	\N	Out of Service	0	\N	Out of service for Fuel leak	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E11	Engine	002-14	e11	E 11	Reserve	Station 2	ENGINE
54	E21	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E21	Engine	002-16	e21	E 21	Reserve	Fire Fleet	ENGINE
55	E31	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E31	Engine	002-10	e31	E 31	Reserve	Station 2	ENGINE
56	L11	\N	\N	\N	\N	In Service	0	\N	In service as L1 now	2026-01-26 10:00:06	2026-01-26 10:00:06	LADDER L11	Ladder	002-6	l11	L 11	Reserve	Station 1	LADDER
57	R-1033	\N	\N	\N	\N	Out of Service	0	\N	Radiator failed after overheat	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE 1033	Rescue	1033	r1033	\N	Reserve	Station 2	RESCUE
58	R-1034	\N	\N	\N	\N	In Service	0	\N	In service Sunday for event	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE 1034	Rescue	1034	r1034	\N	Reserve	Station 2	RESCUE
38	E1	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E1	Engine	20503	e1	E 1	Station 1	Station 1	ENGINE
39	L1	\N	\N	\N	\N	Out of Service	0	\N	Back from expert. Can be used as spare. Needs one more repair.	2026-01-26 10:00:06	2026-01-26 10:00:06	LADDER L1	Ladder	002-12	l1	L 1	Station 1	Fire Fleet	LADDER
40	R1	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R1	Rescue	16508	r1	R 1	Station 1	Station 1	RESCUE
41	R11	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R11	Rescue	19502	r11	R 11	Station 1	Station 1	RESCUE
46	R22	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R22	Rescue	19503	r22	R 22	Station 2	Station 2	RESCUE
47	E3	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E3	Engine	002-22	e3	E 3	Station 3	Station 3	ENGINE
48	L3	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	LADDER L3	Ladder	17505	l3	L 3	Station 3	Station 3	LADDER
49	R3	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R3	Rescue	17501	r3	R 3	Station 3	Station 3	RESCUE
50	E4	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E4	Engine	20504	e4	E 4	Station 4	Station 4	ENGINE
51	R4	\N	\N	\N	\N	In Service	0	\N	In service Sunday	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R4	Rescue	17502	r4	R 4	Station 4	Station 4	RESCUE
52	R44	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R44	Rescue	17503	r44	R 44	Station 4	Station 4	RESCUE
59	R-1035	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE 1035	Rescue	1035	r1035	\N	Reserve	Station 1	RESCUE
60	R-1036	\N	\N	\N	\N	In Service	0	\N	In service Sunday for event	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE 1036	Rescue	1036	r1036	\N	Reserve	Station 2	RESCUE
61	R-14500	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE 14500	Rescue	14500	r14500	\N	Reserve	Station 2	RESCUE
62	R-14501	\N	\N	\N	\N	In Service	0	\N	In service as rescue 4	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE 14501	Rescue	14501	r14501	\N	Reserve	Station 4	RESCUE
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.cache (key, value, expiration) FROM stdin;
spatie.permission.cache	a:3:{s:5:"alias";a:0:{}s:11:"permissions";a:0:{}s:5:"roles";a:0:{}}	1769613961
build_sha	s:40:"d818c15a2b39ea66cac534348aa6f8007123dc76";	1769527815
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: capital_projects; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.capital_projects (id, name, description, budget_amount, start_date, target_completion_date, actual_completion, notes, created_at, updated_at, project_number, status, priority, ai_priority_rank, ai_priority_score, ai_reasoning, last_ai_analysis) FROM stdin;
20	FIRE STATION #4 – ROOF REPLACEMENT	Critical roof replacement project for Fire Station #4. The existing roof has reached end of life and requires complete replacement to prevent water damage and maintain structural integrity. Highest priority infrastructure project.	357000.00	2026-01-26	2027-01-26	\N	\N	2026-01-26 16:28:28	2026-01-26 16:28:28	63731	pending	critical	\N	\N	\N	\N
21	FIRE STATION #2 – REPL. EXHAUST SYS	Replacement of vehicle exhaust extraction system at Fire Station #2. Part of department-wide initiative to upgrade all station exhaust systems for improved air quality and firefighter health.	200000.00	2026-03-26	2026-09-26	\N	\N	2026-01-26 16:28:28	2026-01-26 16:28:28	65127	pending	medium	\N	\N	\N	\N
22	FIRE STATION #3 – REPL. EXHAUST SYS	Replacement of vehicle exhaust extraction system at Fire Station #3. This project will eliminate diesel exhaust exposure in the apparatus bay and improve overall air quality for personnel.	228000.00	2026-03-26	2026-09-26	\N	\N	2026-01-26 16:28:28	2026-01-26 16:28:28	66527	pending	medium	\N	\N	\N	\N
23	FIRE STATION #4 – REPL. EXHAUST SYS	Secondary exhaust system replacement project for Fire Station #4 apparatus bay expansion. Complements the initial exhaust system project to cover additional bays.	177054.00	2026-04-26	2026-10-26	\N	\N	2026-01-26 16:28:28	2026-01-26 16:28:28	66727-B	pending	medium	\N	\N	\N	\N
24	FIRE STATION #2 – VEHICLE AWNING REPL	Replacement of vehicle awning structure at Fire Station #2. The existing awning provides weather protection for apparatus and personnel during vehicle operations. Project includes structural improvements and modern materials.	237357.00	2026-02-26	2026-11-26	\N	\N	2026-01-26 16:28:28	2026-01-26 16:28:28	60626	pending	high	\N	\N	\N	\N
17	FIRE STATION #4 – REPL. EXHAUST SYS	Replacement of vehicle exhaust system at Fire Station #4 to improve air quality and safety. Includes removal of old system, installation of new exhaust extraction equipment, and testing.	22946.00	2026-02-09	2026-07-26	\N	\N	2026-01-26 16:28:28	2026-01-26 16:28:28	66727	pending	medium	\N	\N	\N	\N
18	FIRE STATION #1 – REPL. EXHAUST SYS	Complete replacement of the vehicle exhaust extraction system at Fire Station #1. This is a high-priority project to ensure firefighter health and safety by eliminating diesel exhaust exposure in the apparatus bay.	285000.00	2026-02-26	2026-10-26	\N	\N	2026-01-26 16:28:28	2026-01-26 16:28:28	67927	pending	high	\N	\N	\N	\N
19	FIRE STATION #2 – RESTROOM/PLUMBING	Major renovation of restroom facilities and plumbing infrastructure at Fire Station #2. Includes replacement of aging pipes, fixtures, ADA-compliant upgrades, and modernization of facilities.	255000.00	2026-02-16	2026-11-26	\N	\N	2026-01-26 16:28:28	2026-01-26 16:28:28	63631	pending	high	\N	\N	\N	\N
\.


--
-- Data for Name: equipment_items; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.equipment_items (id, name, normalized_name, category, description, manufacturer, unit_of_measure, reorder_min, reorder_max, location_id, is_active, created_at, updated_at) FROM stdin;
4	Mounts	mounts	General Equipment	\N	\N	each	1	2	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
5	Aerial Master Stream Tips	aerial master stream tips	Nozzles & Tips	\N	\N	each	1	4	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
6	Stream Straightener	stream straightener	General Equipment	\N	\N	each	1	1	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
7	Nozzle Teeth Packs	nozzle teeth packs	Nozzles & Tips	\N	\N	each	4	17	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
8	Stortz Caps	stortz caps	Fittings & Adapters	\N	\N	each	1	6	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
9	4" Cap	4 cap	Fittings & Adapters	\N	\N	each	1	1	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
10	5" Caps	5 caps	Fittings & Adapters	\N	\N	each	1	4	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
11	6" Caps	6 caps	Fittings & Adapters	\N	\N	each	2	8	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
12	6" Gaskets	6 gaskets	Fittings & Adapters	\N	\N	each	1	4	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
13	5" Gaskets	5 gaskets	Fittings & Adapters	\N	\N	each	4	16	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
14	5" Suction Gaskets	5 suction gaskets	Fittings & Adapters	\N	\N	each	2	10	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
15	2 1/2" Gaskets	2 12 gaskets	Fittings & Adapters	\N	\N	each	4	18	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
16	1 1/2" Gaskets	1 12 gaskets	Fittings & Adapters	\N	\N	each	2	9	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
17	Misc. Gaskets	misc gaskets	Fittings & Adapters	\N	\N	each	1	4	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
18	6" to 4" Reducers	6 to 4 reducers	Fittings & Adapters	\N	\N	each	1	4	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
19	6" to 2" Reducer	6 to 2 reducer	Fittings & Adapters	\N	\N	each	1	1	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
20	4" to 2 1/2" Reducers	4 to 2 12 reducers	Fittings & Adapters	\N	\N	each	1	2	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
21	Stortz Connection with 4" Male	stortz connection with 4 male	Fittings & Adapters	\N	\N	each	1	6	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
22	Stortz Connection with 5" Male	stortz connection with 5 male	Fittings & Adapters	\N	\N	each	1	4	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
23	Stortz Connection with 6" Male	stortz connection with 6 male	Fittings & Adapters	\N	\N	each	1	1	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
24	Stortz Connection with 6" Female	stortz connection with 6 female	Fittings & Adapters	\N	\N	each	1	2	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
25	5" to 4" Reducers	5 to 4 reducers	Fittings & Adapters	\N	\N	each	1	3	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
26	4 1/2" Adapter	4 12 adapter	Fittings & Adapters	\N	\N	each	1	1	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
27	Stortz Connection with 4" Female	stortz connection with 4 female	Fittings & Adapters	\N	\N	each	1	1	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
28	Hydrant Assist Valve	hydrant assist valve	Hydrant Tools	\N	\N	each	1	1	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
29	Intake	intake	General Equipment	\N	\N	each	1	1	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
30	Stortz Elbow to 4" Female	stortz elbow to 4 female	Fittings & Adapters	\N	\N	each	1	5	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
31	Misc. Adapters	misc adapters	Fittings & Adapters	\N	\N	each	1	2	42	t	2026-01-25 18:12:52	2026-01-26 01:16:10
32	Foam Boot	foam boot	Training	\N	\N	each	2	8	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
33	75psi 175gpm Fog Tips	75psi 175gpm fog tips	Nozzles & Tips	\N	\N	each	2	10	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
34	100psi 325gpm Fog Tips	100psi 325gpm fog tips	Nozzles & Tips	\N	\N	each	1	1	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
35	75psi 200gpm Fog Tips	75psi 200gpm fog tips	Nozzles & Tips	\N	\N	each	1	1	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
36	Selectomatic Nozzle Tip	selectomatic nozzle tip	Nozzles & Tips	\N	\N	each	1	1	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
37	Other Fog Tips	other fog tips	Nozzles & Tips	\N	\N	each	1	5	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
38	Glow in the Dark Stream Adjusters	glow in the dark stream adjusters	General Equipment	\N	\N	each	1	2	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
39	Bag of Brass Set Screws	bag of brass set screws	Storage/Containers	\N	\N	each	1	1	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
40	Red Box Misc.	red box misc	Storage/Containers	\N	\N	each	1	1	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
41	Appliance Mounts	appliance mounts	General Equipment	\N	\N	each	2	9	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
42	Handle Playpipes	handle playpipes	Sprinkler/Plumbing	\N	\N	each	1	3	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
43	Incline Gates	incline gates	General Equipment	\N	\N	each	1	3	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
44	1" Breakaways Bails	1 breakaways bails	General Equipment	\N	\N	each	1	6	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
45	1 1/2" Breakaway Bails	1 12 breakaway bails	General Equipment	\N	\N	each	1	6	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
46	Water Thiefs	water thiefs	General Equipment	\N	\N	each	1	6	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
47	Ground Y Supply	ground y supply	General Equipment	\N	\N	each	1	3	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
48	Ground Supply	ground supply	General Equipment	\N	\N	each	1	3	43	t	2026-01-25 18:12:52	2026-01-26 01:16:10
49	Blitzfire	blitzfire	General Equipment	\N	\N	each	1	1	44	t	2026-01-25 18:12:52	2026-01-26 01:16:10
50	Strainers	strainers	General Equipment	\N	\N	each	1	3	44	t	2026-01-25 18:12:52	2026-01-26 01:16:10
51	Hose Edge Protectors	hose edge protectors	Hose Appliances	\N	\N	each	1	3	44	t	2026-01-25 18:12:52	2026-01-26 01:16:10
80	Wye 2.5"	wye 25	Hose Appliances	\N	\N	each	0	2	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
52	Pick Headed Axe	pick headed axe	Forcible Entry	\N	\N	each	1	6	47	t	2026-01-25 18:12:52	2026-01-26 01:16:10
53	Flat Headed Axe	flat headed axe	Forcible Entry	\N	\N	each	1	5	47	t	2026-01-25 18:12:52	2026-01-26 01:16:10
54	Sledge Hammer	sledge hammer	Hand Tools	\N	\N	each	1	6	47	t	2026-01-25 18:12:52	2026-01-26 01:16:10
125	Dyke Cutters	dyke cutters	Cutting Tools	\N	\N	each	0	2	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
55	1 1/4" Nozzle Tips	1 14 nozzle tips	Nozzles & Tips	\N	\N	each	0	3	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
56	1" Nozzle Tip	1 nozzle tip	Nozzles & Tips	\N	\N	each	0	3	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
57	1 3/8" Nozzle Tips	1 38 nozzle tips	Nozzles & Tips	\N	\N	each	0	2	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
58	1 1/2" Nozzle Tip	1 12 nozzle tip	Nozzles & Tips	\N	\N	each	0	1	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
59	1 3/4" Nozzle Tip	1 34 nozzle tip	Nozzles & Tips	\N	\N	each	0	1	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
60	2" Nozzle Tip	2 nozzle tip	Nozzles & Tips	\N	\N	each	0	1	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
61	1 1/8" Nozzle Tip	1 18 nozzle tip	Nozzles & Tips	\N	\N	each	0	1	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
62	2 1/2" Elbows	2 12 elbows	General Equipment	\N	\N	each	0	7	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
63	1 1/2" Double Males	1 12 double males	General Equipment	\N	\N	each	0	9	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
64	1 1/2" Couplings	1 12 couplings	Fittings & Adapters	\N	\N	each	0	7	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
65	2 1/2 Cap Pressure Gauge	2 12 cap pressure gauge	Fittings & Adapters	\N	\N	each	0	3	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
66	Inline Pressure Gauge	inline pressure gauge	General Equipment	\N	\N	each	0	1	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
67	2 1/2" to 1/2" Reducer	2 12 to 12 reducer	Fittings & Adapters	\N	\N	each	0	1	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
68	2 1/2" Female Caps	2 12 female caps	Fittings & Adapters	\N	\N	each	0	5	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
69	2 1/2" Male Caps	2 12 male caps	Fittings & Adapters	\N	\N	each	0	3	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
70	1 1/2" Female Caps	1 12 female caps	Fittings & Adapters	\N	\N	each	0	3	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
71	2 1/2 to 1" Reducers	2 12 to 1 reducers	Fittings & Adapters	\N	\N	each	0	12	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
72	Gated Wye	gated wye	Hose Appliances	\N	\N	each	0	4	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
73	Gate Valves	gate valves	General Equipment	\N	\N	each	0	2	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
74	Double Male 2 1/2"	double male 2 12	General Equipment	\N	\N	each	0	21	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
75	Double Female 2 1/2"	double female 2 12	General Equipment	\N	\N	each	0	15	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
76	2 1/2" Couplings	2 12 couplings	Fittings & Adapters	\N	\N	each	0	2	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
77	Siamese 2.5" with clapper valves	siamese 25 with clapper valves	Hose Appliances	\N	Akron	each	0	3	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
78	Siamese with 5" storz connection	siamese with 5 storz connection	Hose Appliances	\N	\N	each	0	2	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
79	Trimese 2.5"	trimese 25	General Equipment	\N	\N	each	0	1	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
81	Hose Jacket	hose jacket	Hose Appliances	\N	\N	each	0	1	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
82	Foam Pick up tubes	foam pick up tubes	Training	\N	\N	each	0	2	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
83	Turbo draft (small)	turbo draft small	General Equipment	\N	\N	each	0	1	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
84	Drafting appliances	drafting appliances	General Equipment	\N	\N	each	0	2	44	t	2026-01-26 01:16:10	2026-01-26 20:02:13
85	Training Foam	training foam	Training	\N	\N	each	0	5	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
86	Auto Wash	auto wash	General Equipment	\N	\N	each	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
87	Fog Fluid	fog fluid	Nozzles & Tips	\N	\N	each	0	2	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
88	TK Charger	tk charger	General Equipment	\N	\N	each	0	5	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
89	Vector Fog Machine	vector fog machine	Nozzles & Tips	\N	\N	each	0	3	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
90	Marq Fog Machine	marq fog machine	Nozzles & Tips	\N	\N	each	0	2	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
91	4" PVC Pipe	4 pvc pipe	Sprinkler/Plumbing	\N	\N	each	0	4	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
92	8" PVC Pipe	8 pvc pipe	Sprinkler/Plumbing	\N	\N	each	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
93	Sprinkler Wedge	sprinkler wedge	Sprinkler/Plumbing	\N	\N	each	0	7	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
94	Pipe Clamp	pipe clamp	Sprinkler/Plumbing	\N	\N	each	0	4	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
95	Male/Female Threaded PVC Caps 1" - 3/4"	malefemale threaded pvc caps 1  34	Sprinkler/Plumbing	\N	\N	bag	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
96	Glue on PVC Caps	glue on pvc caps	Sprinkler/Plumbing	\N	\N	each	0	5	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
97	Cone Pipe Plug	cone pipe plug	Sprinkler/Plumbing	\N	\N	each	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
98	Well Test	well test	General Equipment	\N	\N	each	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
99	Crowbar (Shelf D)	crowbar shelf d	Forcible Entry	\N	\N	each	0	5	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
100	Ball-peen Hammer	ballpeen hammer	Hand Tools	\N	\N	each	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
101	Hammer (Shelf D)	hammer shelf d	Hand Tools	\N	\N	each	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
102	511 Tool	511 tool	General Equipment	\N	\N	each	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
103	Spanner Wrench (Shelf D)	spanner wrench shelf d	Hand Tools	\N	\N	each	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
104	Assortment of Allen Wrenches	assortment of allen wrenches	Hand Tools	\N	\N	each	0	1	45	t	2026-01-26 01:16:10	2026-01-26 20:02:13
105	Decon System	decon system	General Equipment	\N	\N	each	0	25	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
106	Blankets	blankets	General Equipment	\N	\N	each	0	4	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
107	Duffle Bag	duffle bag	Storage/Containers	\N	\N	each	0	5	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
108	Struts with Attachments	struts with attachments	Rescue Equipment	\N	\N	each	0	8	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
109	Tool box	tool box	Storage/Containers	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
110	AAA Batteries	aaa batteries	General Equipment	\N	\N	each	0	24	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
111	AA Batteries	aa batteries	General Equipment	\N	\N	each	0	28	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
112	D Battery	d battery	Electrical	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
113	Allen Wrench Set Metric	allen wrench set metric	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
114	Allen Wrench Set SAE	allen wrench set sae	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
115	Allen Wrench Set Metric/SAE	allen wrench set metricsae	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
116	Box Cutter	box cutter	Cutting Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
117	Frangible Bulb Sprinkler Head	frangible bulb sprinkler head	Sprinkler/Plumbing	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
118	Flat-headed Screwdriver	flatheaded screwdriver	Hand Tools	\N	\N	each	0	2	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
119	Philips Screwdriver	philips screwdriver	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
120	Mini Philips Screwdriver	mini philips screwdriver	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
121	Torx Screwdriver	torx screwdriver	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
122	Lockout	lockout	Lockout/Entry	\N	\N	each	0	8	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
123	Open-ended Wrench	openended wrench	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
124	Slip-Joint Pliers	slipjoint pliers	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
126	Mini Hacksaw	mini hacksaw	Cutting Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
127	Vice Grips	vice grips	General Equipment	\N	\N	each	0	3	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
128	Adjustable Wrench	adjustable wrench	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
129	Wire Cutter	wire cutter	Cutting Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
130	Crowbar (Shelf E)	crowbar shelf e	Forcible Entry	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
131	Hammer (Shelf E)	hammer shelf e	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
132	Pipe-Wrench	pipewrench	Hand Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
133	Air Cutting Chisel	air cutting chisel	General Equipment	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
134	20in Chainsaw Blade	20in chainsaw blade	Cutting Tools	\N	\N	each	0	4	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
135	Chainsaw Chain	chainsaw chain	Cutting Tools	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
136	9in Sawzaw Blade	9in sawzaw blade	Cutting Tools	\N	\N	box	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
137	Dremel	dremel	General Equipment	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
138	12in Hacksaw Blade	12in hacksaw blade	Cutting Tools	\N	\N	each	0	15	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
139	Carbide Sawzaw Blade	carbide sawzaw blade	Cutting Tools	\N	\N	each	0	12	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
140	Air Lube	air lube	General Equipment	\N	\N	each	0	2	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
141	4-6in Spanner Wrench	46in spanner wrench	Hand Tools	\N	\N	each	0	8	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
142	5in Spanner Wrench	5in spanner wrench	Hand Tools	\N	\N	each	0	13	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
143	Smoke Trainer	smoke trainer	General Equipment	\N	\N	box	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
144	Come-along	comealong	General Equipment	\N	\N	each	0	2	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
145	Come-along Bar	comealong bar	Forcible Entry	\N	\N	each	0	6	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
146	Rope Edge Protection	rope edge protection	General Equipment	\N	\N	each	0	2	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
147	Dewalt Carrying Bag	dewalt carrying bag	Storage/Containers	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
148	Hydraram	hydraram	General Equipment	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
149	Rescue Tech Bag	rescue tech bag	Rescue Equipment	\N	\N	each	0	1	46	t	2026-01-26 01:16:10	2026-01-26 20:02:13
150	Chainsaw Safety Chap	chainsaw safety chap	Cutting Tools	\N	\N	box	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
151	Yates	yates	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
152	Frisbees	frisbees	General Equipment	\N	\N	box	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
153	12x15 4-mil Clear Bags	12x15 4mil clear bags	Storage/Containers	\N	\N	boxes	0	2	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
154	Big Easy Carrying Bag	big easy carrying bag	Storage/Containers	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
155	Pop-up Traffic Cone with Carrying Bag	popup traffic cone with carrying bag	Storage/Containers	\N	\N	each	0	4	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
156	Universal Lockout Tool Set	universal lockout tool set	Lockout/Entry	\N	\N	each	0	4	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
157	Air Wedge	air wedge	Lockout/Entry	\N	\N	each	0	6	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
158	Glassmaster	glassmaster	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
159	K-Tool	ktool	General Equipment	\N	\N	each	0	3	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
160	Search and Rescue Gloves	search and rescue gloves	Rescue Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:13
161	Flag Pole Mount	flag pole mount	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
162	Conversion Kit	conversion kit	General Equipment	\N	\N	each	0	2	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
163	Spring Rope Hook	spring rope hook	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
164	Wedge Pack	wedge pack	Lockout/Entry	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
165	Cooler Cable	cooler cable	General Equipment	\N	\N	each	0	5	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
166	Access Tool	access tool	Lockout/Entry	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
167	Access Tool Kit	access tool kit	Lockout/Entry	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
168	Cutters Edge Tool Sling	cutters edge tool sling	Cutting Tools	\N	\N	each	0	5	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
169	Hot Stick	hot stick	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
170	AC Voltage Detector	ac voltage detector	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
171	Red Case with Steel Rods	red case with steel rods	Storage/Containers	\N	\N	each	0	2	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
172	Access Tool Bag	access tool bag	Lockout/Entry	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
173	Mini Sledge	mini sledge	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
174	Rubber Mallet	rubber mallet	Hand Tools	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
175	Crowbar (Shelf F)	crowbar shelf f	Forcible Entry	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
176	Style-50 Bar	style50 bar	Forcible Entry	\N	\N	each	0	11	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
177	Mini Shovel	mini shovel	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
178	Mini Halligan	mini halligan	Forcible Entry	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
179	Storm Drain Tool	storm drain tool	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
180	Hacksaw	hacksaw	Cutting Tools	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
181	Quick Strap Mounting System	quick strap mounting system	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
182	Box of Forcible Entry Tool Straps	box of forcible entry tool straps	Storage/Containers	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
183	36in Bolt Cutters	36in bolt cutters	Cutting Tools	\N	\N	each	0	3	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
184	2 Sided Spannered Hydrant Wrench	2 sided spannered hydrant wrench	Hand Tools	\N	\N	each	0	4	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
185	1 Sided Spannered Hydrant Wrench	1 sided spannered hydrant wrench	Hand Tools	\N	\N	each	0	3	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
186	Hydrant Wrench	hydrant wrench	Hand Tools	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
187	FLIR TIC Case	flir tic case	Storage/Containers	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
188	Carpenter Square	carpenter square	General Equipment	\N	\N	each	0	3	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
189	Keiser Deadblow 10lb	keiser deadblow 10lb	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
190	Sprinkler Assortment in Ammo Can	sprinkler assortment in ammo can	Sprinkler/Plumbing	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
191	Water Can	water can	General Equipment	\N	\N	each	0	5	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
192	CO2 Can	co2 can	General Equipment	\N	\N	each	0	1	47	t	2026-01-26 01:16:10	2026-01-26 20:02:14
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: import_runs; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.import_runs (id, type, file_path, rows_processed, items_created, items_updated, metadata, user_id, started_at, completed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: inventory_locations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.inventory_locations (id, location_name, shelf, "row", bin, notes, created_at, updated_at) FROM stdin;
2	Supply Closet	A	1	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
3	Supply Closet	A	2	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
4	Supply Closet	A	3	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
5	Supply Closet	A	4	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
6	Supply Closet	B	1	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
7	Supply Closet	B	2	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
8	Supply Closet	B	3	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
9	Supply Closet	B	4	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
10	Supply Closet	C	1	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
11	Supply Closet	F	3	\N	\N	2026-01-25 18:12:52	2026-01-25 18:12:52
42	Shelf A	A	4	1	Storage shelf A	2026-01-26 01:16:09	2026-01-26 20:02:13
43	Shelf B	B	4	1	Storage shelf B	2026-01-26 01:16:09	2026-01-26 20:02:13
44	Shelf C	C	4	1	Storage shelf C	2026-01-26 01:16:09	2026-01-26 20:02:13
45	Shelf D	D	3	1	Storage shelf D	2026-01-26 01:16:09	2026-01-26 20:02:13
46	Shelf E	E	4	1	Storage shelf E	2026-01-26 01:16:10	2026-01-26 20:02:13
47	Shelf F	F	4	1	Storage shelf F	2026-01-26 01:16:10	2026-01-26 20:02:14
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
1	default	{"uuid":"5e0333a3-bef9-4432-80b8-620da0ada239","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:2;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/2\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:36:\\"You have been assigned to: Box Truck\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"1d0aa222-e38d-458e-a0f2-979c950e7ec1\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=3bd5496f0cbb4d5e96c3b7f3a59b8a14,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.edit-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.386344","sentry_trace_parent_data":"3bd5496f0cbb4d5e96c3b7f3a59b8a14-137a1d4e32eb4412-0","sentry_publish_time":1769422463.340167}	0	\N	1769422463	1769422463
2	default	{"uuid":"24d37b76-0da3-4b9d-8a35-45ba5fd99956","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:2;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/1\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:47:\\"You have been assigned to: Loose item equipment\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"c80721a5-9891-4d26-81a9-a72eec0820bf\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=47897a44c8114ba082ffb2bb67a4305d,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.edit-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.336541","sentry_trace_parent_data":"47897a44c8114ba082ffb2bb67a4305d-e93680da38974701-0","sentry_publish_time":1769422479.630161}	0	\N	1769422479	1769422479
3	default	{"uuid":"60fef923-655d-4f4c-8fd2-746af4f20c6c","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:2;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/3\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:43:\\"You have been assigned to: SCBA maintenance\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"d8c4ca7e-5c8e-4587-89c0-4c4d8ce486fb\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=adba7919029a483aa75cb9bbf151f285,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.145122","sentry_trace_parent_data":"adba7919029a483aa75cb9bbf151f285-08ea49ce75384215-0","sentry_publish_time":1769430501.35178}	0	\N	1769430501	1769430501
4	default	{"uuid":"992f3765-b56a-4806-8310-f0c6972608f6","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:2;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/4\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:45:\\"You have been assigned to: ECO Battery Recall\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"a236a545-0ca9-4a42-a5b7-721c40841d15\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=1c8dba15b1794cd2a510bd777c83d2e6,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.50793","sentry_trace_parent_data":"1c8dba15b1794cd2a510bd777c83d2e6-295bea8a18f44257-0","sentry_publish_time":1769430648.671395}	0	\N	1769430648	1769430648
5	default	{"uuid":"7747cf18-e7ad-4f6e-93f2-cda1bedb8ffb","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:2;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/5\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:63:\\"You have been assigned to: Arrange bunker gear cleaning install\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"1521a828-a72a-4d53-ac25-8c684aa4e866\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=9eecabfe7f3340e5beed8280d0d255b1,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.34095","sentry_trace_parent_data":"9eecabfe7f3340e5beed8280d0d255b1-bbc583e7482643cd-0","sentry_publish_time":1769430711.427721}	0	\N	1769430711	1769430711
6	default	{"uuid":"51d85211-1397-407b-a61e-3559dd284a42","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:2;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/6\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:58:\\"You have been assigned to: Bunker Gear Drying Equip. Quote\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"e857c2cc-ec64-4aa2-b35c-5a30844e36e5\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=f30f99546a7c4cf386ace3f9a10d3c02,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.25181","sentry_trace_parent_data":"f30f99546a7c4cf386ace3f9a10d3c02-6ec8dfb15793449e-0","sentry_publish_time":1769430779.24136}	0	\N	1769430779	1769430779
7	default	{"uuid":"62402a1e-30b5-49fe-af66-fecad7ddcd24","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:1;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/7\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:37:\\"You have been assigned to: SOG Review\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"a9d944d3-543e-4abc-b7b3-32423f003dbf\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=a822de659e1648c08556ca9e3ac645ca,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.27945","sentry_trace_parent_data":"a822de659e1648c08556ca9e3ac645ca-32828fb1e93e4501-0","sentry_publish_time":1769434945.946444}	0	\N	1769434945	1769434945
8	default	{"uuid":"668bca81-b9bd-43c0-bed2-7a8439c31b3c","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:2;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/7\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:37:\\"You have been assigned to: SOG Review\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"345af304-725f-4e49-850e-c79671af1af1\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=a822de659e1648c08556ca9e3ac645ca,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.27945","sentry_trace_parent_data":"a822de659e1648c08556ca9e3ac645ca-32828fb1e93e4501-0","sentry_publish_time":1769434945.953944}	0	\N	1769434945	1769434945
9	default	{"uuid":"79661e50-7f26-4092-b3f8-c60bc8a3aa43","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:2;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/8\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:50:\\"You have been assigned to: Send sensits for repair\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"5a7febd0-83e1-4327-88d0-2f923c6195da\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=04aa2a001dde4f8cb012eaade071f1a5,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.139564","sentry_trace_parent_data":"04aa2a001dde4f8cb012eaade071f1a5-56613371739841c6-0","sentry_publish_time":1769454000.276951}	0	\N	1769454000	1769454000
10	default	{"uuid":"edb00155-687e-4ddc-b251-fcd249922184","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:2;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:49:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/9\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:55:\\"You have been assigned to: Pick up chainsaws from fleet\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"28f62c50-76c6-441d-9bf8-13f032a6f649\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=35ba459342584e1aa6ef6f755eefbffb,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.359092","sentry_trace_parent_data":"35ba459342584e1aa6ef6f755eefbffb-906cd54c5e4544ba-0","sentry_publish_time":1769454149.050748}	0	\N	1769454149	1769454149
11	default	{"uuid":"3c5b2d26-78e8-4338-b5a2-52b2c7908b9a","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:1;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:50:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/10\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:46:\\"You have been assigned to: Baseball hat design\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"795aad08-081f-4560-ab43-242a5513eca6\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=58574fcbe90e44519bfc811dd3f29ac3,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.730554","sentry_trace_parent_data":"58574fcbe90e44519bfc811dd3f29ac3-eeb0daf6f52b4c19-0","sentry_publish_time":1769466628.082242}	0	\N	1769466628	1769466628
12	default	{"uuid":"b82a1eca-9872-4eb3-8a62-1949cccfa942","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:3;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:50:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/10\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:46:\\"You have been assigned to: Baseball hat design\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"20447930-8221-43ac-bdab-800efb443c45\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=58574fcbe90e44519bfc811dd3f29ac3,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.730554","sentry_trace_parent_data":"58574fcbe90e44519bfc811dd3f29ac3-eeb0daf6f52b4c19-0","sentry_publish_time":1769466628.091337}	0	\N	1769466628	1769466628
13	default	{"uuid":"f6a6c5da-4a1a-4b73-9c2f-7a544a47078b","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:1;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:50:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/11\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:44:\\"You have been assigned to: White Polo sizing\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"598a2c04-0f7e-4016-8987-d7af5f2b4815\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=79ff420c84264e64a122914170f0f378,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.483806","sentry_trace_parent_data":"79ff420c84264e64a122914170f0f378-8709759f21fe4ccd-0","sentry_publish_time":1769466744.005476}	0	\N	1769466744	1769466744
14	default	{"uuid":"493cab82-d705-4a53-8125-5fb3ce0a717a","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:3;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:50:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/11\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:44:\\"You have been assigned to: White Polo sizing\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"843903c1-1d77-4921-9b13-7fe0033937fd\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=79ff420c84264e64a122914170f0f378,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.483806","sentry_trace_parent_data":"79ff420c84264e64a122914170f0f378-8709759f21fe4ccd-0","sentry_publish_time":1769466744.008398}	0	\N	1769466744	1769466744
15	default	{"uuid":"78a4c797-f5dc-41c4-9f6d-0a727332488a","displayName":"Filament\\\\Notifications\\\\DatabaseNotification","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","command":"O:48:\\"Illuminate\\\\Notifications\\\\SendQueuedNotifications\\":3:{s:11:\\"notifiables\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:15:\\"App\\\\Models\\\\User\\";s:2:\\"id\\";a:1:{i:0;i:6;}s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}s:12:\\"notification\\";O:43:\\"Filament\\\\Notifications\\\\DatabaseNotification\\":2:{s:4:\\"data\\";a:11:{s:7:\\"actions\\";a:1:{i:0;a:21:{s:4:\\"name\\";s:4:\\"view\\";s:5:\\"color\\";N;s:5:\\"event\\";N;s:9:\\"eventData\\";a:0:{}s:17:\\"dispatchDirection\\";b:0;s:19:\\"dispatchToComponent\\";N;s:15:\\"extraAttributes\\";a:0:{}s:4:\\"icon\\";N;s:12:\\"iconPosition\\";E:42:\\"Filament\\\\Support\\\\Enums\\\\IconPosition:Before\\";s:8:\\"iconSize\\";N;s:10:\\"isOutlined\\";b:0;s:10:\\"isDisabled\\";b:0;s:5:\\"label\\";s:9:\\"View Todo\\";s:11:\\"shouldClose\\";b:0;s:16:\\"shouldMarkAsRead\\";b:1;s:18:\\"shouldMarkAsUnread\\";b:0;s:21:\\"shouldOpenUrlInNewTab\\";b:0;s:4:\\"size\\";E:39:\\"Filament\\\\Support\\\\Enums\\\\ActionSize:Small\\";s:7:\\"tooltip\\";N;s:3:\\"url\\";s:50:\\"https:\\/\\/support.darleyplex.com\\/admin\\/todos\\/11\\/edit\\";s:4:\\"view\\";s:29:\\"filament-actions::link-action\\";}}s:4:\\"body\\";s:44:\\"You have been assigned to: White Polo sizing\\";s:5:\\"color\\";N;s:8:\\"duration\\";s:10:\\"persistent\\";s:4:\\"icon\\";s:34:\\"heroicon-o-clipboard-document-list\\";s:9:\\"iconColor\\";s:7:\\"primary\\";s:6:\\"status\\";N;s:5:\\"title\\";s:17:\\"New Todo Assigned\\";s:4:\\"view\\";s:36:\\"filament-notifications::notification\\";s:8:\\"viewData\\";a:0:{}s:6:\\"format\\";s:8:\\"filament\\";}s:2:\\"id\\";s:36:\\"45ee0bdd-9107-468e-b6a1-1801c8c7f895\\";}s:8:\\"channels\\";a:1:{i:0;s:8:\\"database\\";}}"},"sentry_baggage_data":"sentry-trace_id=79ff420c84264e64a122914170f0f378,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.todo-resource.pages.create-todo,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.483806","sentry_trace_parent_data":"79ff420c84264e64a122914170f0f378-8709759f21fe4ccd-0","sentry_publish_time":1769466744.009878}	0	\N	1769466744	1769466744
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_01_20_170835_create_apparatuses_table	1
5	2026_01_20_170835_create_stations_table	1
6	2026_01_20_170836_create_capital_projects_table	1
7	2026_01_20_170836_create_uniforms_table	1
8	2026_01_20_170837_create_shop_works_table	1
9	2026_01_20_210149_create_notifications_table	1
10	2026_01_20_213439_alter_notifications_data_to_jsonb	1
11	2026_01_25_142628_create_permission_tables	2
12	2026_01_21_000002_create_apparatus_inspections_table	3
13	2026_01_21_000003_create_apparatus_defects_table	3
14	2026_01_21_142803_alter_capital_projects_table_for_ai_tracking	3
15	2026_01_21_142809_create_project_milestones_table	3
16	2026_01_21_142810_create_project_updates_table	3
17	2026_01_21_142811_create_notification_tracking_table	3
18	2026_01_21_142812_create_ai_analysis_logs_table	3
19	2026_01_22_000000_create_stock_mutations_table	3
20	2026_01_22_000001_create_inventory_locations_table	3
21	2026_01_22_000001_create_todos_table	3
22	2026_01_22_000001_fix_defects_inspections_linkage	3
23	2026_01_22_000002_create_equipment_items_table	3
24	2026_01_22_000002_create_tasks_table	3
25	2026_01_22_000003_create_apparatus_defect_recommendations_table	3
26	2026_01_22_000004_create_apparatus_inventory_allocations_table	3
27	2026_01_22_000005_add_name_slug_to_apparatuses	3
28	2026_01_22_000005_create_admin_alert_events_table	3
29	2026_01_22_000006_add_photo_path_to_apparatus_defects	3
30	2026_01_22_000006_create_import_runs_table	3
31	2026_01_22_000007_enable_pg_trgm_extension	3
32	2026_01_22_210722_add_columns_to_todos_table	3
33	2026_01_23_000001_add_profile_fields_to_users_table	3
34	2026_01_23_000001_alter_todos_assignment_columns	3
35	2026_01_23_192413_create_personal_access_tokens_table	3
36	2026_01_23_192731_add_must_change_password_to_users_table	3
37	2026_01_21_000001_create_apparatuses_table	4
38	2026_01_23_200541_add_photo_to_apparatus_defects_table	1
39	2026_01_25_000001_fix_stock_mutations_columns	5
40	2026_01_25_000002_add_attachments_to_todos_table	6
41	2026_01_26_000001_update_apparatuses_for_csv_columns	6
42	2026_01_26_000001_add_priority_to_shop_works_table	7
43	2026_01_26_141315_add_status_priority_to_todos_table	8
44	2026_01_26_000001_add_attachments_to_todos_table	9
45	2026_01_26_000002_create_todo_updates_table	9
46	2026_01_26_000003_add_status_field_to_todos_table	10
47	2026_01_26_000004_fix_existing_todos_data	11
48	2026_01_26_180000_create_todo_updates_table	12
49	2026_01_26_201515_create_push_subscriptions_table	13
\.


--
-- Data for Name: model_has_permissions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.model_has_permissions (permission_id, model_type, model_id) FROM stdin;
\.


--
-- Data for Name: model_has_roles; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.model_has_roles (role_id, model_type, model_id) FROM stdin;
\.


--
-- Data for Name: notification_tracking; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.notification_tracking (id, user_id, project_id, notification_type, sent_at, read_at, actioned_at, action_taken, snoozed_until, metadata, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.notifications (id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: permissions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.permissions (id, name, guard_name, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: personal_access_tokens; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.personal_access_tokens (id, tokenable_type, tokenable_id, name, token, abilities, last_used_at, expires_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: project_milestones; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.project_milestones (id, capital_project_id, title, description, due_date, completed, completed_at, created_at, updated_at) FROM stdin;
13	18	Design Phase Complete	Architectural and engineering designs finalized and approved.	2026-04-26	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
14	18	Permits Obtained	All necessary building permits and approvals secured.	2026-04-26	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
15	18	Construction Started	Construction phase begins with contractor mobilization.	2026-05-26	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
16	18	Final Inspection	Final walkthrough and inspection completed, punch list items addressed.	2026-09-26	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
17	19	Design Phase Complete	Architectural and engineering designs finalized and approved.	2026-04-16	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
18	19	Permits Obtained	All necessary building permits and approvals secured.	2026-05-16	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
19	19	Construction Started	Construction phase begins with contractor mobilization.	2026-06-16	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
20	19	Final Inspection	Final walkthrough and inspection completed, punch list items addressed.	2026-10-16	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
21	20	Design Phase Complete	Architectural and engineering designs finalized and approved.	2026-03-26	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
22	20	Permits Obtained	All necessary building permits and approvals secured.	2026-05-26	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
23	20	Construction Started	Construction phase begins with contractor mobilization.	2026-06-26	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
24	20	Final Inspection	Final walkthrough and inspection completed, punch list items addressed.	2026-12-26	f	\N	2026-01-26 16:28:28	2026-01-26 16:28:28
\.


--
-- Data for Name: project_updates; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.project_updates (id, capital_project_id, user_id, update_text, created_at) FROM stdin;
\.


--
-- Data for Name: push_subscriptions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.push_subscriptions (id, subscribable_type, subscribable_id, endpoint, public_key, auth_token, content_encoding, created_at, updated_at) FROM stdin;
1	App\\Models\\User	3	https://web.push.apple.com/QGeGHd9585Zw9lD_Olb1tnd8jHe-ZjaALoM8bDSus3p_inBCgOdFxjOPAsZcxPZLvIaVc0qRzHFDp-s4TsQNrOUaG91fDUdJW08ngc0-pGEu1OhNaPQtTgewdNh4u4sGD-nP4jfh_mCroDKU_TVziTBLQyLioP6p6bVhwWhlKO0	BFp5Svws6yO8edFJ6Cpp70gedtRrD7VJTS7kJehetwUEh0PfFuWXfcJAhleeJ4rSMeFcr-8HIyhWQupqeMEf4Xo	AOSzZPLdYWfmYv4a5ov7GA	\N	2026-01-27 09:21:30	2026-01-27 09:21:30
2	App\\Models\\User	2	https://web.push.apple.com/QDVmwKMeonLVeSs0CRwSOLg-yJbE10jDveglq7aZgC9GaSgJ6suwKUsVgp-eacNdo7W_d1kjO6hhgK4JdkNQ4U21_BWyyIzRrjTrzH4OA0uxuhTMw6RGnC_48kPXH1Npu4Y07up1UbjJAirh0UxvGGQwRW9shxjxqaLR2neIMFE	BJ2hueMtWTaK1Uo1Cl9aTrVHzxdU-MZM3odqIuD83vpkxnuWrXwJd2oENn_0Sobo94xeOvvZlN1AT8yj4Juu7ME	_PiwxFoyWCSeKGZmm93whQ	\N	2026-01-27 13:34:16	2026-01-27 13:34:16
\.


--
-- Data for Name: role_has_permissions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.role_has_permissions (permission_id, role_id) FROM stdin;
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.roles (id, name, guard_name, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
RzbfFC66tpu5Dja9ahG2jaCTefb7GLVVVnrZBh2A	\N	104.209.5.147	Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Mobile Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiUndQdTVPMmtxSlZOQlNXbUl2UEU4bjRNZk1pdUFnSlNlZk05RFF5UyI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MzA6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbSI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1769521781
7TcwjeS5kz2YzRcBHTwUhqMjjRv1rsKH0qS80OFa	\N	8.21.220.30	curl/8.16.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoiTFRuNGhjZzlMMzZuOElCMVBKN2dMTUFTNWlWZDhKY0JTb25xSlhXZyI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6NDI6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbS9hZG1pbi9sb2dpbiI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1769522533
77j8Zj73CgHA6LbhuGupqHf4cTHyCweYMuKGalEJ	\N	52.225.73.161	Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Mobile Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiS1lrV21TSGF2bGRGbmJWU2tJeThqc3EzUlZESmZMcWxVckUxUHJQNSI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MzA6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbSI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1769524300
smgQfczd8SoalkdC7lEuBnreZrStbUCUJ524cLhn	\N	91.207.106.233	Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiY1JWdEwxQ1Znb3lLYlQyNXFzN2diaXdmM2FSYXY4TGlQcHc5YjBnTSI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MjY6Imh0dHA6Ly8xNDUuMjIzLjczLjE3MDo4MDgwIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769526811
6RYnI3sMCzz2pHqUdY0Mwx58Vysonm7PIB9lF4bY	2	174.211.163.8	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	YTo1OntzOjY6Il90b2tlbiI7czo0MDoicllUVjhpVnBiUTFlaUI3Q0VTOG5PN1I1MzZyaTNNWGJ3V214UnVNcSI7czo1MDoibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MjtzOjE3OiJwYXNzd29yZF9oYXNoX3dlYiI7czo2MDoiJDJ5JDEyJFRWdGc2TktaUURGWkprMlpQZWNVcGVkSTBNbmRZeGtBdXdiLzZUa2ZDRlJ3VWRLellmLkZtIjtzOjk6Il9wcmV2aW91cyI7YToxOntzOjM6InVybCI7czozNjoiaHR0cHM6Ly9zdXBwb3J0LmRhcmxleXBsZXguY29tL2FkbWluIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769523396
fykK6PTR1zDTrm8fYNddImfs5TsHP873nffWdns6	2	174.211.163.8	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	YTo1OntzOjY6Il90b2tlbiI7czo0MDoiY1hPSXc4VkJqM2JGSWlNZHRYbzBrS3dMdlI0OXhwcFBRODRrV1l1NyI7czo1MDoibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MjtzOjE3OiJwYXNzd29yZF9oYXNoX3dlYiI7czo2MDoiJDJ5JDEyJFRWdGc2TktaUURGWkprMlpQZWNVcGVkSTBNbmRZeGtBdXdiLzZUa2ZDRlJ3VWRLellmLkZtIjtzOjk6Il9wcmV2aW91cyI7YToxOntzOjM6InVybCI7czozNjoiaHR0cHM6Ly9zdXBwb3J0LmRhcmxleXBsZXguY29tL2FkbWluIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769520897
txt5z1YXTacT8Hfs6xDb4YbZryUPrafa606ILMef	3	8.21.220.30	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0	YTo2OntzOjY6Il90b2tlbiI7czo0MDoiNXAxOVhOZGoxWGd0NEtuN3BmYUVNSUVBRldKUUpsYVZsUmxNVGN5OSI7czo1MDoibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MztzOjk6Il9wcmV2aW91cyI7YToxOntzOjM6InVybCI7czozNjoiaHR0cHM6Ly9zdXBwb3J0LmRhcmxleXBsZXguY29tL2FkbWluIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319czoxNzoicGFzc3dvcmRfaGFzaF93ZWIiO3M6NjA6IiQyeSQxMiRzYXZaa0lPYVBIQ0doZDJGVE4wbWx1emNDUjgyR2ZldmVHRGI3dndYc1VjQXJRMnNVczU2aSI7czo2OiJ0YWJsZXMiO2E6MTp7czo0MToiZWJlZGEwODY2ZTk2YTcyYzJjMzU0MTU1ZjZiNGNiODZfcGVyX3BhZ2UiO3M6MjoiMjUiO319	1769527778
DD8XA7bVrRuOIzmbCjqAanUinxzDGNgWZKCyQBaD	\N	52.173.237.211	curl/8.5.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoibkVlV2ZzY2poSnl0WkVYSTlJNjR2alAySFNvZ2tTRlJoOVNjNjd5YSI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6NDA6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbS9fX3ZlcnNpb24iO31zOjY6Il9mbGFzaCI7YToyOntzOjM6Im9sZCI7YTowOnt9czozOiJuZXciO2E6MDp7fX19	1769524319
xcEzP1y1sxDJ0m5i9jlaTM7xYU5Bruk7mHatRlgj	\N	172.183.133.250	curl/8.5.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoiVExFN29JUUJodXNHTEF4OWtXMnZBUUVSQ1hJOWJQSGVuTTV2b2pmaiI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6NDA6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbS9fX3ZlcnNpb24iO31zOjY6Il9mbGFzaCI7YToyOntzOjM6Im9sZCI7YTowOnt9czozOiJuZXciO2E6MDp7fX19	1769521805
O0oarGJmDMNxqLjjmH9KB75qndbLNvGazXoTYziH	\N	139.59.2.234	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiZmd4cjZReXo0MnBRa1ZWUGdua2VjTU9udFRyZTBJYmwzeUJ1aUFlNiI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MjY6Imh0dHA6Ly8xNDUuMjIzLjczLjE3MDo4MDgwIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769523809
h166IeKNA7d5JtzYyi0cZZ0rTQPk4opc2KrGd4qy	3	8.21.220.30	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	YTo2OntzOjY6Il90b2tlbiI7czo0MDoieTl4N2RVTElWeEZwWVZHa0JCOWlBTmZTaWZ2Qm5UdlBQbkFzbjR6ZCI7czozOiJ1cmwiO2E6MDp7fXM6OToiX3ByZXZpb3VzIjthOjE6e3M6MzoidXJsIjtzOjM2OiJodHRwczovL3N1cHBvcnQuZGFybGV5cGxleC5jb20vYWRtaW4iO31zOjY6Il9mbGFzaCI7YToyOntzOjM6Im9sZCI7YTowOnt9czozOiJuZXciO2E6MDp7fX1zOjUwOiJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI7aTozO3M6MTc6InBhc3N3b3JkX2hhc2hfd2ViIjtzOjYwOiIkMnkkMTIkc2F2WmtJT2FQSENHaGQyRlROMG1sdXpjQ1I4MkdmZXZlR0RiN3Z3WHNVY0FyUTJzVXM1NmkiO30=	1769521151
RfyF6rbKeIQJrx4eoYCCoua4lhud0bDAyJdhgwC0	\N	85.185.169.11	Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/51.0.2704.103 Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiWFFnUU0yNm9ibWJwVWtmR1IxcDRoVnM4cDhSazFBTFptcGt4ZHkwWCI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MjY6Imh0dHA6Ly8xNDUuMjIzLjczLjE3MDo4MDgwIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769518561
EOIfL8sNSESnA5HB2rwPHOoD12Bet9NFJ5MXLzkE	\N	145.223.73.170	curl/8.5.0	YToyOntzOjY6Il90b2tlbiI7czo0MDoiSmRjZkVXaGg2Z3NTUlBPdVo2dVhWVDlUWTc5MTZuMmR1VjBrTUhWMCI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769519139
BN1Op6oV8rlXTjGl4CA7C4fPPQ4FglY9mo7CmOPa	\N	145.223.73.170	curl/8.5.0	YToyOntzOjY6Il90b2tlbiI7czo0MDoiSmRrMFFmaUFxbFhQVVJ0RTNIdHR1SGdlclBQNU45UU9nb2hwUktTbSI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769522230
S5le4fYqUeLYi2QAjjanjlECGP4HhUfiG64QOQQD	3	8.21.220.30	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	YTo1OntzOjY6Il90b2tlbiI7czo0MDoiU09vdkZBTmdFZDBEZGthSFNBTkl1OE9MUHJzelhaaU9VYUQ4UUNkNiI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6NDU6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbS9hZG1pbi9zZXR0aW5ncyI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fXM6NTA6ImxvZ2luX3dlYl81OWJhMzZhZGRjMmIyZjk0MDE1ODBmMDE0YzdmNThlYTRlMzA5ODlkIjtpOjM7czoxNzoicGFzc3dvcmRfaGFzaF93ZWIiO3M6NjA6IiQyeSQxMiRzYXZaa0lPYVBIQ0doZDJGVE4wbWx1emNDUjgyR2ZldmVHRGI3dndYc1VjQXJRMnNVczU2aSI7fQ==	1769527755
UemqrDP9UMjZVlr4Mcb1gl89D2XYmoO4vLuSszRG	\N	145.223.73.170	curl/8.5.0	YToyOntzOjY6Il90b2tlbiI7czo0MDoiZjVJWUlwMzZBZG9PdFR5OG53UlgzbHdQQnJPTUdBakxzSzMzYnZlbiI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769524611
\.


--
-- Data for Name: shop_works; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.shop_works (id, project_name, description, apparatus_id, status, parts_list, estimated_cost, actual_cost, started_date, completed_date, assigned_to, notes, created_at, updated_at, priority, category, quantity) FROM stdin;
1	2-Post Car Lift (10,000 lb capacity)	\N	\N	Pending	\N	4500.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	1	Lifting Equipment	1
2	4-Post Truck Lift (30,000 lb capacity)	\N	\N	Pending	\N	18000.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	2	Lifting Equipment	1
3	Heavy-Duty Mobile Column Lifts (Set of 4)	\N	\N	Pending	\N	25000.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	3	Lifting Equipment	1
4	Transmission Jack (1,500 lb capacity)	\N	\N	Pending	\N	800.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	4	Lifting Equipment	1
5	Engine Hoist/Cherry Picker (2-ton)	\N	\N	Pending	\N	600.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	5	Lifting Equipment	1
6	Floor Jack (3-ton)	\N	\N	Pending	\N	400.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	6	Jacks & Stands	2
7	Floor Jack (20-ton for heavy apparatus)	\N	\N	Pending	\N	1200.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	7	Jacks & Stands	1
8	Jack Stands (6-ton pair)	\N	\N	Pending	\N	150.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	8	Jacks & Stands	2
9	Jack Stands (25-ton pair)	\N	\N	Pending	\N	500.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	9	Jacks & Stands	2
10	Bottle Jacks (assorted capacities)	\N	\N	Pending	\N	300.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	10	Jacks & Stands	4
11	Pneumatic Impact Wrench Set	\N	\N	Pending	\N	800.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	11	Tools & Maintenance Equipment	1
12	Brake Lathe	\N	\N	Pending	\N	5000.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	12	Tools & Maintenance Equipment	1
13	Tire Changer (Heavy-duty)	\N	\N	Pending	\N	8000.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	13	Tools & Maintenance Equipment	1
14	Wheel Balancer	\N	\N	Pending	\N	3500.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	14	Tools & Maintenance Equipment	1
15	Parts Washer	\N	\N	Pending	\N	1500.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	15	Tools & Maintenance Equipment	1
16	Diagnostic Scanner (Heavy-Duty Commercial)	\N	\N	Pending	\N	6000.00	\N	\N	\N	\N	\N	2026-01-26 03:54:01	2026-01-26 03:54:01	16	Tools & Maintenance Equipment	1
\.


--
-- Data for Name: stations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.stations (id, station_number, address, city, state, zip_code, captain_in_charge, phone, notes, created_at, updated_at) FROM stdin;
29	1	1051 Jefferson Ave.	Miami Beach	FL	33139	\N	305.673.7135	\N	2026-01-25 18:20:01	2026-01-26 01:16:09
30	2	2300 Pine Tree Dr.	Miami Beach	FL	33140	\N	305.673.7171	\N	2026-01-25 18:20:01	2026-01-26 01:16:09
31	3	5303 Collins Ave.	Miami Beach	FL	33140	\N	305.673.7179	\N	2026-01-25 18:20:01	2026-01-26 01:16:09
32	4	6880 Indian Creek Dr.	Miami Beach	FL	33141	\N	305.673.7136	\N	2026-01-25 18:20:01	2026-01-26 01:16:09
\.


--
-- Data for Name: stock_mutations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.stock_mutations (id, stockable_type, stockable_id, reference, amount, description, created_at, updated_at) FROM stdin;
80	App\\Models\\EquipmentItem	86	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
81	App\\Models\\EquipmentItem	87	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
82	App\\Models\\EquipmentItem	88	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
83	App\\Models\\EquipmentItem	89	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
84	App\\Models\\EquipmentItem	90	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
85	App\\Models\\EquipmentItem	91	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
86	App\\Models\\EquipmentItem	92	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
87	App\\Models\\EquipmentItem	93	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
88	App\\Models\\EquipmentItem	94	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
89	App\\Models\\EquipmentItem	95	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
90	App\\Models\\EquipmentItem	96	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
91	App\\Models\\EquipmentItem	97	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
92	App\\Models\\EquipmentItem	98	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
94	App\\Models\\EquipmentItem	100	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
95	App\\Models\\EquipmentItem	101	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
96	App\\Models\\EquipmentItem	102	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
97	App\\Models\\EquipmentItem	103	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
98	App\\Models\\EquipmentItem	104	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
99	App\\Models\\EquipmentItem	105	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
100	App\\Models\\EquipmentItem	106	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
101	App\\Models\\EquipmentItem	107	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
102	App\\Models\\EquipmentItem	108	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
103	App\\Models\\EquipmentItem	109	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
124	App\\Models\\EquipmentItem	130	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
125	App\\Models\\EquipmentItem	131	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
130	App\\Models\\EquipmentItem	136	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
137	App\\Models\\EquipmentItem	143	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
144	App\\Models\\EquipmentItem	150	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
146	App\\Models\\EquipmentItem	152	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
147	App\\Models\\EquipmentItem	153	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
148	App\\Models\\EquipmentItem	154	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
172	App\\Models\\EquipmentItem	175	Initial Stock	0	Initial inventory stock setup	2026-01-26 01:46:53	2026-01-26 01:46:53
190	App\\Models\\EquipmentItem	4	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
191	App\\Models\\EquipmentItem	5	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
192	App\\Models\\EquipmentItem	6	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
193	App\\Models\\EquipmentItem	7	\N	17	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
194	App\\Models\\EquipmentItem	8	\N	6	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
195	App\\Models\\EquipmentItem	9	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
196	App\\Models\\EquipmentItem	10	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
197	App\\Models\\EquipmentItem	11	\N	8	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
198	App\\Models\\EquipmentItem	12	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
199	App\\Models\\EquipmentItem	13	\N	16	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
200	App\\Models\\EquipmentItem	14	\N	10	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
201	App\\Models\\EquipmentItem	15	\N	18	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
202	App\\Models\\EquipmentItem	16	\N	9	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
203	App\\Models\\EquipmentItem	17	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
204	App\\Models\\EquipmentItem	18	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
205	App\\Models\\EquipmentItem	19	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
206	App\\Models\\EquipmentItem	20	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
207	App\\Models\\EquipmentItem	21	\N	6	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
208	App\\Models\\EquipmentItem	22	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
209	App\\Models\\EquipmentItem	23	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
210	App\\Models\\EquipmentItem	24	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
211	App\\Models\\EquipmentItem	25	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
212	App\\Models\\EquipmentItem	26	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
213	App\\Models\\EquipmentItem	27	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
214	App\\Models\\EquipmentItem	28	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
215	App\\Models\\EquipmentItem	29	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
216	App\\Models\\EquipmentItem	30	\N	5	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
217	App\\Models\\EquipmentItem	31	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
218	App\\Models\\EquipmentItem	32	\N	8	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
219	App\\Models\\EquipmentItem	33	\N	10	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
220	App\\Models\\EquipmentItem	34	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
221	App\\Models\\EquipmentItem	35	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
222	App\\Models\\EquipmentItem	36	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
223	App\\Models\\EquipmentItem	37	\N	5	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
224	App\\Models\\EquipmentItem	38	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
225	App\\Models\\EquipmentItem	39	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
226	App\\Models\\EquipmentItem	40	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
227	App\\Models\\EquipmentItem	41	\N	9	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
228	App\\Models\\EquipmentItem	42	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
229	App\\Models\\EquipmentItem	43	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
230	App\\Models\\EquipmentItem	44	\N	6	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
231	App\\Models\\EquipmentItem	45	\N	6	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
232	App\\Models\\EquipmentItem	46	\N	6	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
233	App\\Models\\EquipmentItem	47	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
234	App\\Models\\EquipmentItem	48	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
235	App\\Models\\EquipmentItem	49	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
236	App\\Models\\EquipmentItem	50	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
237	App\\Models\\EquipmentItem	51	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
238	App\\Models\\EquipmentItem	55	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
239	App\\Models\\EquipmentItem	56	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
240	App\\Models\\EquipmentItem	57	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
241	App\\Models\\EquipmentItem	58	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
242	App\\Models\\EquipmentItem	59	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
243	App\\Models\\EquipmentItem	60	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
244	App\\Models\\EquipmentItem	61	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
245	App\\Models\\EquipmentItem	62	\N	7	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
246	App\\Models\\EquipmentItem	63	\N	9	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
247	App\\Models\\EquipmentItem	64	\N	7	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
248	App\\Models\\EquipmentItem	65	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
249	App\\Models\\EquipmentItem	66	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
250	App\\Models\\EquipmentItem	67	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
251	App\\Models\\EquipmentItem	68	\N	5	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
252	App\\Models\\EquipmentItem	69	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
253	App\\Models\\EquipmentItem	70	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
254	App\\Models\\EquipmentItem	71	\N	12	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
255	App\\Models\\EquipmentItem	72	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
256	App\\Models\\EquipmentItem	73	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
257	App\\Models\\EquipmentItem	74	\N	21	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
258	App\\Models\\EquipmentItem	75	\N	15	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
259	App\\Models\\EquipmentItem	76	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
260	App\\Models\\EquipmentItem	77	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
261	App\\Models\\EquipmentItem	78	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
262	App\\Models\\EquipmentItem	79	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
263	App\\Models\\EquipmentItem	80	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
264	App\\Models\\EquipmentItem	81	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
265	App\\Models\\EquipmentItem	82	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
266	App\\Models\\EquipmentItem	83	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
267	App\\Models\\EquipmentItem	84	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
268	App\\Models\\EquipmentItem	85	\N	5	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
269	App\\Models\\EquipmentItem	110	\N	24	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
270	App\\Models\\EquipmentItem	111	\N	28	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
271	App\\Models\\EquipmentItem	112	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
272	App\\Models\\EquipmentItem	113	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
273	App\\Models\\EquipmentItem	114	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
274	App\\Models\\EquipmentItem	115	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
275	App\\Models\\EquipmentItem	116	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
276	App\\Models\\EquipmentItem	117	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
277	App\\Models\\EquipmentItem	118	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
278	App\\Models\\EquipmentItem	119	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
279	App\\Models\\EquipmentItem	120	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
280	App\\Models\\EquipmentItem	121	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
281	App\\Models\\EquipmentItem	122	\N	8	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
282	App\\Models\\EquipmentItem	123	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
283	App\\Models\\EquipmentItem	124	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
284	App\\Models\\EquipmentItem	125	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
285	App\\Models\\EquipmentItem	126	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
286	App\\Models\\EquipmentItem	127	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
287	App\\Models\\EquipmentItem	128	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
288	App\\Models\\EquipmentItem	129	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
289	App\\Models\\EquipmentItem	99	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
291	App\\Models\\EquipmentItem	132	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
292	App\\Models\\EquipmentItem	133	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
293	App\\Models\\EquipmentItem	134	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
294	App\\Models\\EquipmentItem	135	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
295	App\\Models\\EquipmentItem	137	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
296	App\\Models\\EquipmentItem	138	\N	15	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
297	App\\Models\\EquipmentItem	139	\N	12	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
298	App\\Models\\EquipmentItem	140	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
299	App\\Models\\EquipmentItem	141	\N	8	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
300	App\\Models\\EquipmentItem	142	\N	13	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
301	App\\Models\\EquipmentItem	144	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
302	App\\Models\\EquipmentItem	145	\N	6	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
303	App\\Models\\EquipmentItem	146	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
304	App\\Models\\EquipmentItem	147	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
305	App\\Models\\EquipmentItem	148	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
306	App\\Models\\EquipmentItem	149	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
307	App\\Models\\EquipmentItem	151	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
308	App\\Models\\EquipmentItem	155	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
309	App\\Models\\EquipmentItem	156	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
310	App\\Models\\EquipmentItem	157	\N	6	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
311	App\\Models\\EquipmentItem	158	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
312	App\\Models\\EquipmentItem	159	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
313	App\\Models\\EquipmentItem	160	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
314	App\\Models\\EquipmentItem	161	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
315	App\\Models\\EquipmentItem	162	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
316	App\\Models\\EquipmentItem	163	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
317	App\\Models\\EquipmentItem	164	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
318	App\\Models\\EquipmentItem	165	\N	5	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
319	App\\Models\\EquipmentItem	166	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
320	App\\Models\\EquipmentItem	167	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
321	App\\Models\\EquipmentItem	168	\N	5	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
322	App\\Models\\EquipmentItem	169	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
323	App\\Models\\EquipmentItem	170	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
324	App\\Models\\EquipmentItem	171	\N	2	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
325	App\\Models\\EquipmentItem	172	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
326	App\\Models\\EquipmentItem	52	\N	6	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
327	App\\Models\\EquipmentItem	53	\N	5	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
328	App\\Models\\EquipmentItem	54	\N	6	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
329	App\\Models\\EquipmentItem	173	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
330	App\\Models\\EquipmentItem	174	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
331	App\\Models\\EquipmentItem	176	\N	11	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
332	App\\Models\\EquipmentItem	177	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
333	App\\Models\\EquipmentItem	178	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
334	App\\Models\\EquipmentItem	179	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
335	App\\Models\\EquipmentItem	180	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
336	App\\Models\\EquipmentItem	181	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
337	App\\Models\\EquipmentItem	182	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
338	App\\Models\\EquipmentItem	183	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
339	App\\Models\\EquipmentItem	184	\N	4	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
340	App\\Models\\EquipmentItem	185	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
341	App\\Models\\EquipmentItem	186	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
342	App\\Models\\EquipmentItem	187	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
343	App\\Models\\EquipmentItem	188	\N	3	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
344	App\\Models\\EquipmentItem	189	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
345	App\\Models\\EquipmentItem	190	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
346	App\\Models\\EquipmentItem	191	\N	5	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
347	App\\Models\\EquipmentItem	192	\N	1	\N	2026-01-26 20:07:35	2026-01-26 20:07:35
\.


--
-- Data for Name: tasks; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.tasks (id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: todo_updates; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.todo_updates (id, todo_id, user_id, username, comment, created_at, updated_at) FROM stdin;
2	7	3	Peter Darley	Shared link: \n\nhttps://miamibeach-my.sharepoint.com/:w:/r/personal/peterdarley_miamibeachfl_gov/Documents/support_sogs.docx?d=w3aa42a3bbdc84aef8a57516bbd3c366a&csf=1&web=1&e=0XDfmJ	2026-01-26 19:01:15	2026-01-26 19:01:15
3	7	3	Peter Darley	Added notes, highlights, strike throughs and comments up to the last SOG (uniforms). We can discuss and I can make the changes. 	2026-01-27 03:15:51	2026-01-27 03:15:51
\.


--
-- Data for Name: todos; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.todos (id, sort, created_at, updated_at, title, description, is_completed, assigned_to_user_id, due_at, assigned_to, created_by, attachments, status, priority, assigned_by, completed_at, created_by_user_id) FROM stdin;
2	0	2026-01-26 09:29:02	2026-01-26 10:14:23	Box Truck	<p>Review box truck plans / build</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
1	0	2026-01-26 08:58:21	2026-01-26 10:14:39	Loose item equipment	<p>Review new ladder loose item equipment list.</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
3	0	2026-01-26 12:28:21	2026-01-26 12:28:21	SCBA maintenance	<p>Paul Rogers coming today for SCBA maintenance.&nbsp;</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
4	0	2026-01-26 12:30:48	2026-01-26 12:30:48	ECO Battery Recall	<p>Install firmware update on the ECO BATT Generator.&nbsp;&nbsp;</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
5	0	2026-01-26 12:31:51	2026-01-26 12:31:51	Arrange bunker gear cleaning install	<p>Get Gressia to contact company and install the bunger gear cleaning equipment.</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
6	0	2026-01-26 12:32:59	2026-01-26 12:32:59	Bunker Gear Drying Equip. Quote	<p>Company contacted for quote request on bunker gear cleaning for 4 person ambient air system with size specs.&nbsp;&nbsp;</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
7	0	2026-01-26 13:42:25	2026-01-26 13:42:25	SOG Review	<p>Review Support SOGs and provide update / feedback to Chief Mestas.&nbsp;</p>	f	\N	\N	["1","3","2"]	3	["todo-attachments\\/01KFX8N6C6KBMMKZGAZT1V1J1X.pdf"]	pending	medium	\N	\N	\N
8	0	2026-01-26 19:00:00	2026-01-26 19:00:00	Send sensits for repair	<p>Send sensits off to ten-8 or Scott Richardson for repair.&nbsp; Or consider replacing with new ones.</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
9	0	2026-01-26 19:02:29	2026-01-26 19:02:29	Pick up chainsaws from fleet	<p>Pick up chainsaws from fleet on drive in.</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
10	0	2026-01-26 22:30:28	2026-01-26 22:30:28	Baseball hat design	<p>Seek approval of final design to submit to All Uniforms</p>	f	\N	\N	["1","3","2"]	2	[]	pending	medium	\N	\N	\N
11	0	2026-01-26 22:32:23	2026-01-26 22:32:23	White Polo sizing	<p>Size command staff for white polo (with Elbeco sizing kit).&nbsp;</p><p>Submit list to All Uniforms</p>	f	\N	\N	["6","3","1","2"]	2	[]	pending	medium	\N	\N	\N
\.


--
-- Data for Name: uniforms; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.uniforms (id, item_name, size, quantity_on_hand, reorder_level, unit_cost, supplier, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at, display_name, rank, station, phone, must_change_password) FROM stdin;
1	Miguel Anchia	MiguelAnchia@miamibeachfl.gov	\N	$2y$12$n9XvMLjtLRl24x9SD/JuuOOjI941Y5Ya7wP/ixUr4Ln.rCygpzab.	\N	2026-01-25 14:38:43	2026-01-26 00:38:49	\N	\N	\N	\N	f
4	Gerald DeYoung	geralddeyoung@miamibeachfl.gov	\N	$2y$12$7ZYkClglbXtK4v7XKG11kexVYVH9qyogbvZlCh12DSE7urFet7/TW	\N	2026-01-25 14:38:44	2026-01-26 00:38:50	\N	\N	\N	\N	f
5	Admin	mbfdsupport@gmail.com	\N	$2y$12$IZxlAGqcwsSEnvucF1qwOuJlHTZlIsHr22WwYqId4oGCqKTK94xtK	\N	2026-01-26 08:55:34	2026-01-26 08:55:34	Admin	\N	\N	\N	f
3	Peter Darley	PeterDarley@miamibeachfl.gov	\N	$2y$12$savZkIOaPHCGhd2FTN0mluzcCR82GfeveGDb7vwXsUcArQ2sUs56i	JS9AxsCfgA2t0ws5hShVW1svb1ObcqBWuv6fY91gNcjx9CVx0Lp3xHKRDCG9	2026-01-25 14:38:44	2026-01-26 00:38:49	\N	\N	\N	\N	f
2	Richard Quintela	RichardQuintela@miamibeachfl.gov	\N	$2y$12$TVtg6NKZQDFZJk2ZPecUpedI0MndYxkAuwb/6TkfCFRwUdKzYf.Fm	rtyDYozdtuSCmLYSzy9SBoe4QZAvRl9veSsdFIHASOoL9CIa4BhYF6wSgede	2026-01-25 14:38:44	2026-01-26 00:38:49	\N	\N	\N	\N	f
6	Grecia Trabanino	greciatrabanino@miamibeachfl.gov	\N	$2y$12$DnOCOSS4Vs//CWp0q34okeX41hcPSqzzI4N9OFRSbj8WXi9ytCsxq	\N	2026-01-26 13:30:16	2026-01-26 13:30:16	Grecia Trabanino	\N	Admin	\N	f
7	Test User	test@example.com	2026-01-26 16:28:27	$2y$12$vp88KeTsc12jon6sFWj6e.5N8Yz9lcIspK3whOpK2y1rCOiP9h.RO	rDvCA0nGUh	2026-01-26 16:28:28	2026-01-26 16:28:28	\N	\N	\N	\N	f
\.


--
-- Name: admin_alert_events_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.admin_alert_events_id_seq', 1, false);


--
-- Name: ai_analysis_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.ai_analysis_logs_id_seq', 1, false);


--
-- Name: apparatus_defect_recommendations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.apparatus_defect_recommendations_id_seq', 1, false);


--
-- Name: apparatus_defects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.apparatus_defects_id_seq', 1, false);


--
-- Name: apparatus_inspections_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.apparatus_inspections_id_seq', 1, false);


--
-- Name: apparatus_inventory_allocations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.apparatus_inventory_allocations_id_seq', 1, false);


--
-- Name: apparatuses_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.apparatuses_id_seq', 62, true);


--
-- Name: capital_projects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.capital_projects_id_seq', 24, true);


--
-- Name: equipment_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.equipment_items_id_seq', 192, true);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 1, false);


--
-- Name: import_runs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.import_runs_id_seq', 1, false);


--
-- Name: inventory_locations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.inventory_locations_id_seq', 47, true);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.jobs_id_seq', 15, true);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.migrations_id_seq', 49, true);


--
-- Name: notification_tracking_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.notification_tracking_id_seq', 1, false);


--
-- Name: permissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.permissions_id_seq', 1, false);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.personal_access_tokens_id_seq', 1, false);


--
-- Name: project_milestones_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.project_milestones_id_seq', 24, true);


--
-- Name: project_updates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.project_updates_id_seq', 1, false);


--
-- Name: push_subscriptions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.push_subscriptions_id_seq', 2, true);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.roles_id_seq', 1, false);


--
-- Name: shop_works_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.shop_works_id_seq', 16, true);


--
-- Name: stations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.stations_id_seq', 32, true);


--
-- Name: stock_mutations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.stock_mutations_id_seq', 347, true);


--
-- Name: tasks_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.tasks_id_seq', 1, false);


--
-- Name: todo_updates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.todo_updates_id_seq', 3, true);


--
-- Name: todos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.todos_id_seq', 11, true);


--
-- Name: uniforms_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.uniforms_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.users_id_seq', 7, true);


--
-- Name: admin_alert_events admin_alert_events_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.admin_alert_events
    ADD CONSTRAINT admin_alert_events_pkey PRIMARY KEY (id);


--
-- Name: ai_analysis_logs ai_analysis_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.ai_analysis_logs
    ADD CONSTRAINT ai_analysis_logs_pkey PRIMARY KEY (id);


--
-- Name: apparatus_defect_recommendations apparatus_defect_recommendations_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defect_recommendations
    ADD CONSTRAINT apparatus_defect_recommendations_pkey PRIMARY KEY (id);


--
-- Name: apparatus_defects apparatus_defects_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defects
    ADD CONSTRAINT apparatus_defects_pkey PRIMARY KEY (id);


--
-- Name: apparatus_inspections apparatus_inspections_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_inspections
    ADD CONSTRAINT apparatus_inspections_pkey PRIMARY KEY (id);


--
-- Name: apparatus_inventory_allocations apparatus_inventory_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_inventory_allocations
    ADD CONSTRAINT apparatus_inventory_allocations_pkey PRIMARY KEY (id);


--
-- Name: apparatuses apparatuses_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatuses
    ADD CONSTRAINT apparatuses_pkey PRIMARY KEY (id);


--
-- Name: apparatuses apparatuses_slug_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatuses
    ADD CONSTRAINT apparatuses_slug_unique UNIQUE (slug);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: capital_projects capital_projects_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.capital_projects
    ADD CONSTRAINT capital_projects_pkey PRIMARY KEY (id);


--
-- Name: capital_projects capital_projects_project_number_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.capital_projects
    ADD CONSTRAINT capital_projects_project_number_unique UNIQUE (project_number);


--
-- Name: apparatus_defects defect_dedup; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defects
    ADD CONSTRAINT defect_dedup UNIQUE (apparatus_id, compartment, item, resolved);


--
-- Name: equipment_items equipment_items_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.equipment_items
    ADD CONSTRAINT equipment_items_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: import_runs import_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.import_runs
    ADD CONSTRAINT import_runs_pkey PRIMARY KEY (id);


--
-- Name: inventory_locations inventory_locations_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.inventory_locations
    ADD CONSTRAINT inventory_locations_pkey PRIMARY KEY (id);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: inventory_locations location_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.inventory_locations
    ADD CONSTRAINT location_unique UNIQUE (location_name, shelf, "row", bin);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- Name: notification_tracking notification_tracking_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.notification_tracking
    ADD CONSTRAINT notification_tracking_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: permissions permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: project_milestones project_milestones_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.project_milestones
    ADD CONSTRAINT project_milestones_pkey PRIMARY KEY (id);


--
-- Name: project_updates project_updates_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.project_updates
    ADD CONSTRAINT project_updates_pkey PRIMARY KEY (id);


--
-- Name: push_subscriptions push_subscriptions_endpoint_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.push_subscriptions
    ADD CONSTRAINT push_subscriptions_endpoint_unique UNIQUE (endpoint);


--
-- Name: push_subscriptions push_subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.push_subscriptions
    ADD CONSTRAINT push_subscriptions_pkey PRIMARY KEY (id);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: shop_works shop_works_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.shop_works
    ADD CONSTRAINT shop_works_pkey PRIMARY KEY (id);


--
-- Name: stations stations_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.stations
    ADD CONSTRAINT stations_pkey PRIMARY KEY (id);


--
-- Name: stations stations_station_number_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.stations
    ADD CONSTRAINT stations_station_number_unique UNIQUE (station_number);


--
-- Name: stock_mutations stock_mutations_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.stock_mutations
    ADD CONSTRAINT stock_mutations_pkey PRIMARY KEY (id);


--
-- Name: tasks tasks_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.tasks
    ADD CONSTRAINT tasks_pkey PRIMARY KEY (id);


--
-- Name: todo_updates todo_updates_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.todo_updates
    ADD CONSTRAINT todo_updates_pkey PRIMARY KEY (id);


--
-- Name: todos todos_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.todos
    ADD CONSTRAINT todos_pkey PRIMARY KEY (id);


--
-- Name: uniforms uniforms_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.uniforms
    ADD CONSTRAINT uniforms_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: admin_alert_events_created_at_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX admin_alert_events_created_at_index ON public.admin_alert_events USING btree (created_at);


--
-- Name: admin_alert_events_is_read_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX admin_alert_events_is_read_index ON public.admin_alert_events USING btree (is_read);


--
-- Name: admin_alert_events_related_type_related_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX admin_alert_events_related_type_related_id_index ON public.admin_alert_events USING btree (related_type, related_id);


--
-- Name: admin_alert_events_type_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX admin_alert_events_type_index ON public.admin_alert_events USING btree (type);


--
-- Name: ai_analysis_logs_type_executed_at_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX ai_analysis_logs_type_executed_at_index ON public.ai_analysis_logs USING btree (type, executed_at);


--
-- Name: apparatus_defect_recommendations_apparatus_defect_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX apparatus_defect_recommendations_apparatus_defect_id_index ON public.apparatus_defect_recommendations USING btree (apparatus_defect_id);


--
-- Name: apparatus_defect_recommendations_created_at_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX apparatus_defect_recommendations_created_at_index ON public.apparatus_defect_recommendations USING btree (created_at);


--
-- Name: apparatus_defect_recommendations_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX apparatus_defect_recommendations_status_index ON public.apparatus_defect_recommendations USING btree (status);


--
-- Name: apparatus_inventory_allocations_allocated_at_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX apparatus_inventory_allocations_allocated_at_index ON public.apparatus_inventory_allocations USING btree (allocated_at);


--
-- Name: apparatus_inventory_allocations_apparatus_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX apparatus_inventory_allocations_apparatus_id_index ON public.apparatus_inventory_allocations USING btree (apparatus_id);


--
-- Name: apparatus_inventory_allocations_equipment_item_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX apparatus_inventory_allocations_equipment_item_id_index ON public.apparatus_inventory_allocations USING btree (equipment_item_id);


--
-- Name: capital_projects_project_number_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX capital_projects_project_number_index ON public.capital_projects USING btree (project_number);


--
-- Name: equipment_items_is_active_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX equipment_items_is_active_index ON public.equipment_items USING btree (is_active);


--
-- Name: equipment_items_location_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX equipment_items_location_id_index ON public.equipment_items USING btree (location_id);


--
-- Name: equipment_items_manufacturer_category_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX equipment_items_manufacturer_category_index ON public.equipment_items USING btree (manufacturer, category);


--
-- Name: equipment_items_normalized_name_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX equipment_items_normalized_name_index ON public.equipment_items USING btree (normalized_name);


--
-- Name: equipment_items_normalized_name_trgm_idx; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX equipment_items_normalized_name_trgm_idx ON public.equipment_items USING gin (normalized_name public.gin_trgm_ops);


--
-- Name: import_runs_started_at_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX import_runs_started_at_index ON public.import_runs USING btree (started_at);


--
-- Name: import_runs_type_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX import_runs_type_index ON public.import_runs USING btree (type);


--
-- Name: inventory_locations_location_name_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX inventory_locations_location_name_index ON public.inventory_locations USING btree (location_name);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON public.model_has_permissions USING btree (model_id, model_type);


--
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX model_has_roles_model_id_model_type_index ON public.model_has_roles USING btree (model_id, model_type);


--
-- Name: notification_tracking_project_id_notification_type_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX notification_tracking_project_id_notification_type_index ON public.notification_tracking USING btree (project_id, notification_type);


--
-- Name: notification_tracking_user_id_sent_at_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX notification_tracking_user_id_sent_at_index ON public.notification_tracking USING btree (user_id, sent_at);


--
-- Name: notifications_notifiable_type_notifiable_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX notifications_notifiable_type_notifiable_id_index ON public.notifications USING btree (notifiable_type, notifiable_id);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: project_milestones_capital_project_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX project_milestones_capital_project_id_index ON public.project_milestones USING btree (capital_project_id);


--
-- Name: project_updates_capital_project_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX project_updates_capital_project_id_index ON public.project_updates USING btree (capital_project_id);


--
-- Name: project_updates_user_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX project_updates_user_id_index ON public.project_updates USING btree (user_id);


--
-- Name: push_subscriptions_subscribable_morph_idx; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX push_subscriptions_subscribable_morph_idx ON public.push_subscriptions USING btree (subscribable_type, subscribable_id);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: stock_mutations_stocker_type_stocker_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX stock_mutations_stocker_type_stocker_id_index ON public.stock_mutations USING btree (stockable_type, stockable_id);


--
-- Name: admin_alert_events admin_alert_events_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.admin_alert_events
    ADD CONSTRAINT admin_alert_events_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: apparatus_defect_recommendations apparatus_defect_recommendations_apparatus_defect_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defect_recommendations
    ADD CONSTRAINT apparatus_defect_recommendations_apparatus_defect_id_foreign FOREIGN KEY (apparatus_defect_id) REFERENCES public.apparatus_defects(id) ON DELETE CASCADE;


--
-- Name: apparatus_defect_recommendations apparatus_defect_recommendations_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defect_recommendations
    ADD CONSTRAINT apparatus_defect_recommendations_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: apparatus_defect_recommendations apparatus_defect_recommendations_equipment_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defect_recommendations
    ADD CONSTRAINT apparatus_defect_recommendations_equipment_item_id_foreign FOREIGN KEY (equipment_item_id) REFERENCES public.equipment_items(id) ON DELETE SET NULL;


--
-- Name: apparatus_defects apparatus_defects_apparatus_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defects
    ADD CONSTRAINT apparatus_defects_apparatus_id_foreign FOREIGN KEY (apparatus_id) REFERENCES public.apparatuses(id) ON DELETE CASCADE;


--
-- Name: apparatus_defects apparatus_defects_apparatus_inspection_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_defects
    ADD CONSTRAINT apparatus_defects_apparatus_inspection_id_foreign FOREIGN KEY (apparatus_inspection_id) REFERENCES public.apparatus_inspections(id) ON DELETE SET NULL;


--
-- Name: apparatus_inspections apparatus_inspections_apparatus_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_inspections
    ADD CONSTRAINT apparatus_inspections_apparatus_id_foreign FOREIGN KEY (apparatus_id) REFERENCES public.apparatuses(id) ON DELETE CASCADE;


--
-- Name: apparatus_inventory_allocations apparatus_inventory_allocations_allocated_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_inventory_allocations
    ADD CONSTRAINT apparatus_inventory_allocations_allocated_by_user_id_foreign FOREIGN KEY (allocated_by_user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: apparatus_inventory_allocations apparatus_inventory_allocations_apparatus_defect_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_inventory_allocations
    ADD CONSTRAINT apparatus_inventory_allocations_apparatus_defect_id_foreign FOREIGN KEY (apparatus_defect_id) REFERENCES public.apparatus_defects(id) ON DELETE CASCADE;


--
-- Name: apparatus_inventory_allocations apparatus_inventory_allocations_apparatus_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_inventory_allocations
    ADD CONSTRAINT apparatus_inventory_allocations_apparatus_id_foreign FOREIGN KEY (apparatus_id) REFERENCES public.apparatuses(id) ON DELETE CASCADE;


--
-- Name: apparatus_inventory_allocations apparatus_inventory_allocations_equipment_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatus_inventory_allocations
    ADD CONSTRAINT apparatus_inventory_allocations_equipment_item_id_foreign FOREIGN KEY (equipment_item_id) REFERENCES public.equipment_items(id) ON DELETE CASCADE;


--
-- Name: equipment_items equipment_items_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.equipment_items
    ADD CONSTRAINT equipment_items_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.inventory_locations(id) ON DELETE SET NULL;


--
-- Name: import_runs import_runs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.import_runs
    ADD CONSTRAINT import_runs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: model_has_permissions model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: notification_tracking notification_tracking_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.notification_tracking
    ADD CONSTRAINT notification_tracking_project_id_foreign FOREIGN KEY (project_id) REFERENCES public.capital_projects(id) ON DELETE CASCADE;


--
-- Name: notification_tracking notification_tracking_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.notification_tracking
    ADD CONSTRAINT notification_tracking_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: project_milestones project_milestones_capital_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.project_milestones
    ADD CONSTRAINT project_milestones_capital_project_id_foreign FOREIGN KEY (capital_project_id) REFERENCES public.capital_projects(id) ON DELETE CASCADE;


--
-- Name: project_updates project_updates_capital_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.project_updates
    ADD CONSTRAINT project_updates_capital_project_id_foreign FOREIGN KEY (capital_project_id) REFERENCES public.capital_projects(id) ON DELETE CASCADE;


--
-- Name: project_updates project_updates_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.project_updates
    ADD CONSTRAINT project_updates_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: shop_works shop_works_apparatus_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.shop_works
    ADD CONSTRAINT shop_works_apparatus_id_foreign FOREIGN KEY (apparatus_id) REFERENCES public.apparatuses(id) ON DELETE SET NULL;


--
-- Name: todo_updates todo_updates_todo_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.todo_updates
    ADD CONSTRAINT todo_updates_todo_id_foreign FOREIGN KEY (todo_id) REFERENCES public.todos(id) ON DELETE CASCADE;


--
-- Name: todo_updates todo_updates_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.todo_updates
    ADD CONSTRAINT todo_updates_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: todos todos_assigned_to_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.todos
    ADD CONSTRAINT todos_assigned_to_user_id_foreign FOREIGN KEY (assigned_to_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: todos todos_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.todos
    ADD CONSTRAINT todos_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict QDlDXD6GJ7oCdgiBWSWcXPhOyDf4IfYflDQOcgPwkEvh7mPWJFvVxge71BH2cAq

