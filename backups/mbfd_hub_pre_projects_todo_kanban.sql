--
-- PostgreSQL database dump
--

\restrict 2w2BL3CkcFlJzb4zLvZVzXGROIZAOCdtZ5Br6s0ONkWCxsuvekH8rZORO17YxnW

-- Dumped from database version 16.10
-- Dumped by pg_dump version 16.10

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
    resolved boolean DEFAULT false NOT NULL,
    resolved_at timestamp(0) without time zone,
    resolution_notes text,
    defect_history jsonb,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
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
    unit_id character varying(255) NOT NULL,
    vin character varying(255),
    make character varying(255) NOT NULL,
    model character varying(255) NOT NULL,
    year integer,
    status character varying(255) DEFAULT 'In Service'::character varying NOT NULL,
    mileage integer DEFAULT 0 NOT NULL,
    last_service_date date,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT apparatuses_status_check CHECK (((status)::text = ANY ((ARRAY['In Service'::character varying, 'Out of Service'::character varying, 'Maintenance'::character varying])::text[])))
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
    name character varying(255) NOT NULL,
    description text,
    budget_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
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
    stockable_type character varying(255) NOT NULL,
    stockable_id bigint NOT NULL,
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
    updated_at timestamp(0) without time zone
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
-- Name: project_milestones id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.project_milestones ALTER COLUMN id SET DEFAULT nextval('public.project_milestones_id_seq'::regclass);


--
-- Name: project_updates id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.project_updates ALTER COLUMN id SET DEFAULT nextval('public.project_updates_id_seq'::regclass);


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
1	weekly_summary	8	{"summary":"Subject: Weekly Capital Project Portfolio Update - July 17, 2023\\n\\nDear [Executive],\\n\\nI hope this email finds you well. Below is a summary of the capital project portfolio's current status, key achievements, and upcoming milestones.\\n\\nOverall Portfolio Status:\\nThe portfolio is currently in a pending state, with all projects awaiting approval or commencement. The total budget for the projects is $1,343,519.00, with a target completion date ranging from 2026 to 2027.\\n\\nCritical Items Requiring Immediate Attention:\\nTwo critical projects, FIRE STATION #4 - ROOF REPLACEMENT and FIRE STATION #1 - REPL. EXHAUST SYS, are pending approval and require immediate attention. These projects have a high priority and are critical to the department's operations.\\n\\nProjects at Risk:\\nNone of the projects in the portfolio are currently at risk. However, it is essential to monitor the progress of these projects closely to ensure they stay on track.\\n\\nKey Achievements This Week:\\nThis week, we did not have any significant achievements or milestones. However, we are working diligently to move the projects forward and ensure their timely completion.\\n\\nUpcoming Milestones:\\nThe following milestones are upcoming:\\n\\n* FIRE STATION #2 - RESTROOM\\/PLUMBING: Target"}	2026-01-21 22:12:55	2026-01-21 22:13:14	2026-01-21 22:13:14
2	weekly_summary	8	{"summary":"Subject: Weekly Capital Project Portfolio Summary - Week of [Current Date]\\n\\nDear [Executive],\\n\\nI am pleased to provide you with this weekly summary of our capital project portfolio. As of [Current Date], the overall portfolio status remains pending, with no projects completed or overdue.\\n\\nCritical items requiring immediate attention are:\\n\\n* FIRE STATION #4 - ROOF REPLACEMENT: With a critical priority and a target completion date of January 21, 2027, this project requires prompt action to ensure timely completion.\\n\\nProjects at risk are:\\n\\n* FIRE STATION #1 - REPL. EXHAUST SYS: With a high priority and a target completion date of October 21, 2026, this project is at risk of being delayed due to its proximity to the deadline.\\n* FIRE STATION #2 - RESTROOM\\/PLUMBING: With a high priority and a target completion date of November 21, 2026, this project is also at risk of being delayed.\\n\\nKey achievements this week:\\n\\n* None reported.\\n\\nUpcoming milestones:\\n\\n* FIRE STATION #1 - REPL. EXHAUST SYS: Completion date is approaching on October 21, 2026.\\n* FIRE STATION #2 - RESTROOM\\/PLUMBING: Completion date is approaching on November"}	2026-01-21 22:13:08	2026-01-21 22:13:26	2026-01-21 22:13:26
3	weekly_summary	8	{"summary":"Subject: Weekly Capital Project Portfolio Summary\\n\\nDear [Executive],\\n\\nI am pleased to provide you with the weekly summary of our capital project portfolio. As of today, the overall portfolio status remains pending, with no projects completed or overdue.\\n\\nCritical Items Requiring Immediate Attention:\\n\\n* FIRE STATION #4 ROOF REPLACEMENT: As a critical project with a target completion date of January 21, 2027, it is essential that we closely monitor its progress to ensure timely completion.\\n\\nProjects at Risk:\\n\\n* None at this time.\\n\\nKey Achievements This Week:\\n\\n* None to report, as all projects are still in the pending phase.\\n\\nUpcoming Milestones:\\n\\n* FIRE STATION #1 REPL. EXHAUST SYS: Target completion date is October 21, 2026.\\n* FIRE STATION #2 RESTROOM\\/PLUMBING: Target completion date is November 21, 2026.\\n* FIRE STATION #2 VEHICLE AWNING REPL: Target completion date is November 21, 2026.\\n* FIRE STATION #2 REPL. EXHAUST SYS: Target completion date is September 21, 2026.\\n* FIRE STATION #3 REPL. EXHAUST SYS: Target completion date is September 21, 2026.\\n"}	2026-01-21 22:13:09	2026-01-21 22:13:27	2026-01-21 22:13:27
4	weekly_summary	8	{"summary":"Subject: Weekly Capital Project Portfolio Update\\n\\nDear [Executive],\\n\\nI am pleased to provide you with a weekly update on the capital project portfolio. As of today, the overall portfolio status is pending, with all projects still in the planning phase. However, I would like to highlight the following critical items requiring immediate attention:\\n\\n* FIRE STATION #4 - ROOF REPLACEMENT: With a critical priority and a budget of $357,000, this project requires prompt attention to ensure timely completion by January 21, 2027.\\n* FIRE STATION #1 - REPL. EXHAUST SYS: As a high-priority project, it is essential to monitor its progress closely to meet the target completion date of October 21, 2026.\\n\\nProjects at risk of delays or cost overruns include:\\n\\n* FIRE STATION #2 - RESTROOM\\/PLUMBING: With a high priority and a budget of $255,000, this project faces a risk of delays if not closely monitored.\\n* FIRE STATION #2 - VEHICLE AWNING REPL: As another high-priority project, it is crucial to track its progress to meet the target completion date of November 21, 2026.\\n\\nKey achievements this week include:\\n\\n* No significant updates or milestones were achieved"}	2026-01-21 22:13:27	2026-01-21 22:13:45	2026-01-21 22:13:45
5	weekly_summary	8	{"summary":"Subject: Weekly Capital Project Portfolio Summary - July 22, 2026\\n\\nDear [Executive],\\n\\nI am providing you with this weekly summary of the capital project portfolio, highlighting key information and updates. As of July 22, 2026, the overall portfolio status is:\\n\\n* 8 projects are pending, with 3 critical and 5 medium-priority projects.\\n* There are no projects overdue.\\n\\nCritical Items Requiring Immediate Attention:\\n\\n* FIRE STATION #4 - ROOF REPLACEMENT (priority: critical, budget: $357,000, target completion: January 21, 2027) - This project requires immediate attention to ensure timely completion.\\n\\nProjects at Risk:\\n\\n* None\\n\\nKey Achievements This Week:\\n\\n* No significant updates or achievements to report this week.\\n\\nUpcoming Milestones:\\n\\n* FIRE STATION #1 - REPL. EXHAUST SYS: target completion on October 21, 2026\\n* FIRE STATION #2 - RESTROOM\\/PLUMBING: target completion on November 21, 2026\\n* FIRE STATION #2 - VEHICLE AWNING REPL: target completion on November 21, 2026\\n* FIRE STATION #3 - REPL. EXHAUST SYS: target completion on September "}	2026-01-21 22:33:55	2026-01-21 22:34:13	2026-01-21 22:34:13
6	weekly_summary	8	{"summary":"Subject: Weekly Capital Project Portfolio Summary\\n\\nDear [Executive],\\n\\nI am pleased to provide this weekly summary of our capital project portfolio for your review. The portfolio currently consists of 8 projects, with a total budget of $1,544,819.00.\\n\\nOverall Portfolio Status:\\nThe portfolio remains pending, with no projects currently in progress. All projects are scheduled to commence in the coming weeks, and I will provide regular updates on their status.\\n\\nCritical Items Requiring Immediate Attention:\\nFIRE STATION #4 - ROOF REPLACEMENT (Priority: Critical, Budget: $357,000.00, Target Completion: 2027-01-21) is the only project that requires immediate attention due to its critical priority.\\n\\nProjects at Risk:\\nFIRE STATION #1 - REPL. EXHAUST SYS (Priority: High, Budget: $285,000.00, Target Completion: 2026-10-21) and FIRE STATION #2 - RESTROOM\\/PLUMBING (Priority: High, Budget: $255,000.00, Target Completion: 2026-11-21) are at risk of being delayed due to their close proximity to their target completion dates.\\n\\nKey Achievements This Week:\\nNone.\\n\\nUpcoming Milestones:\\n"}	2026-01-21 23:22:54	2026-01-21 23:23:12	2026-01-21 23:23:12
7	weekly_summary	8	{"summary":"Subject: Weekly Capital Projects Portfolio Summary - Week [insert week number]\\n\\nDear [Recipient],\\n\\nI am pleased to provide you with the weekly summary of our capital projects portfolio. As of today, the overall portfolio status remains pending, with all projects still in the planning and preparation phase.\\n\\nCritical items requiring immediate attention are:\\n\\n* FIRE STATION #4 - ROOF REPLACEMENT: With a critical priority and a budget of $357,000, this project requires urgent attention to ensure timely completion by the target date of January 21, 2027.\\n\\nProjects at risk of delay or cost overrun are:\\n\\n* FIRE STATION #1 - REPL. EXHAUST SYS: With a target completion date of October 21, 2026, this project is at risk of delay due to its high priority and relatively short timeline.\\n* FIRE STATION #2 - RESTROOM\\/PLUMBING: This project is also at risk due to its high priority and close target completion date of November 21, 2026.\\n\\nKey achievements this week include:\\n\\n* No new project initiations or significant progress updates reported.\\n\\nUpcoming milestones to watch out for:\\n\\n* FIRE STATION #4 - ROOF REPLACEMENT: Target completion date - January 21, 2027\\n* FIRE"}	2026-01-21 23:23:12	2026-01-21 23:23:30	2026-01-21 23:23:30
8	admin_bullet_summary	0	{"vehicle_inventory":["37 vehicles in total","35 in service"],"out_of_service":["L 1 Pierce Ladder damaged","R 1 Ford Rescue rear AC failure"],"apparatus_issues":[],"equipment_alerts":[],"capital_projects":["4 pending projects: roof replacement, exhaust system"]}	2026-01-21 23:55:55	2026-01-21 23:56:00	2026-01-21 23:56:00
9	admin_bullet_summary	0	{"vehicle_inventory":["37 vehicles in inventory, 35 in service","2 out of service"],"out_of_service":["L 1 - Pierce Ladder engine damaged","R 1 - Ford Rescue rear AC failure"],"apparatus_issues":[],"equipment_alerts":[],"capital_projects":["FIRE STATION #4 - Roof Replacement pending"]}	2026-01-21 23:56:51	2026-01-21 23:56:57	2026-01-21 23:56:57
10	admin_bullet_summary	0	{"raw_response":"{\\n  \\"vehicle_inventory\\": [\\"37 vehicles in total\\", \\"35 in service\\", \\"2 out of service\\"],\\n  \\"out_of_service\\": [\\"L 1 Pierce Ladder damaged\\", \\"R 1 Ford Rescue AC failure\\"],\\n  \\"apparatus_issues\\": [],\\n  \\"equipment_alerts\\": [],\\n  \\"capital_projects\\": [\\"4 pending projects\\", \\"2 high priority\\"]"}	2026-01-21 23:56:57	2026-01-21 23:57:03	2026-01-21 23:57:03
11	admin_bullet_summary	0	{"vehicle_inventory":["37 vehicles in total, 35 in service","2 vehicles out of service"],"out_of_service":["L 1 - Pierce Ladder is damaged"],"apparatus_issues":[],"equipment_alerts":[],"capital_projects":["5 projects pending, 3 high priority"]}	2026-01-21 23:57:03	2026-01-21 23:57:10	2026-01-21 23:57:10
12	admin_bullet_summary	0	{"vehicle_inventory":["37 vehicles in total inventory","35 vehicles in service, 2 out of service"],"out_of_service":["L 1 Pierce Ladder - Engine damaged"],"apparatus_issues":[],"equipment_alerts":[],"capital_projects":["FIRE STATION #4 - Roof replacement pending","FIRE STATION #1 - Exhaust system replacement","FIRE STATION #2 - Restroom and plumbing","FIRE STATION #2 - Vehicle awning replacement","FIRE STATION #2 - Exhaust system replacement"]}	2026-01-21 23:58:27	2026-01-21 23:58:36	2026-01-21 23:58:36
\.


--
-- Data for Name: apparatus_defect_recommendations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.apparatus_defect_recommendations (id, apparatus_defect_id, equipment_item_id, match_method, match_confidence, recommended_qty, reasoning, status, created_by_user_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: apparatus_defects; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.apparatus_defects (id, apparatus_id, compartment, item, status, notes, resolved, resolved_at, resolution_notes, defect_history, created_at, updated_at) FROM stdin;
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

COPY public.apparatuses (id, unit_id, vin, make, model, year, status, mileage, last_service_date, notes, created_at, updated_at) FROM stdin;
1	E31	\N	Pierce	Enforcer	2015	In Service	45000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
2	E32	\N	Pierce	Enforcer	2016	In Service	42000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
3	E33	\N	Pierce	Enforcer	2014	In Service	52000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
4	E34	\N	Pierce	Enforcer	2013	In Service	58000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
5	T31	\N	Pierce	Tower Ladder	2017	In Service	35000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
6	R31	\N	Pierce	Heavy Rescue	2018	In Service	28000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
7	B31	\N	Ford	F-350	2020	In Service	25000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
8	BR31	\N	Ford	F-550 Brush Truck	2012	In Service	48000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
9	BR32	\N	Ford	F-550 Brush Truck	2011	In Service	52000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
10	BT31	\N	Sea Ark	Rescue Boat	2019	In Service	150	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
11	U31	\N	Chevrolet	Silverado 2500	2021	In Service	18000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
12	TK31	\N	Freightliner	Tanker	2010	In Service	65000	\N	\N	2026-01-21 02:47:04	2026-01-21 02:47:04
14	E 21	\N	Pierce	Reserve Engine	2002	In Service	0	\N	Reserve - Vehicle No: 002-16	2026-01-21 10:39:42	2026-01-21 10:39:42
15	E 11	\N	Pierce	Reserve Engine	2002	In Service	0	\N	Reserve - Vehicle No: 002-14	2026-01-21 10:39:42	2026-01-21 10:39:42
16	E 31	\N	Pierce	Reserve Engine	2002	In Service	0	\N	Reserve - Vehicle No: 002-10	2026-01-21 10:39:42	2026-01-21 10:39:42
17	1033	\N	Ford	Rescue	2010	In Service	0	\N	Reserve - Station 1	2026-01-21 10:39:42	2026-01-21 10:39:42
18	1034	\N	Ford	Rescue	2010	In Service	0	\N	Reserve - Station 2	2026-01-21 10:39:42	2026-01-21 10:39:42
19	1035	\N	Ford	Rescue	2010	In Service	0	\N	Reserve - In service as R1	2026-01-21 10:39:42	2026-01-21 10:39:42
20	1036	\N	Ford	Rescue	2010	In Service	0	\N	Reserve - Station 2	2026-01-21 10:39:42	2026-01-21 10:39:42
21	14500	\N	Ford	Rescue	2014	In Service	0	\N	Reserve - LAST OUT RESERVE	2026-01-21 10:39:42	2026-01-21 10:39:42
22	14501	\N	Ford	Rescue	2014	In Service	0	\N	Reserve - Station 3	2026-01-21 10:39:42	2026-01-21 10:39:42
23	L 11	\N	Pierce	Ladder	2002	In Service	0	\N	Reserve - Vehicle No: 002-6 - In service as L1	2026-01-21 10:39:42	2026-01-21 10:39:42
24	E 1	\N	Pierce	Engine	2020	In Service	0	\N	Station 1 - Vehicle No: 20503	2026-01-21 10:39:42	2026-01-21 10:39:42
25	L 1	\N	Pierce	Ladder	2002	Out of Service	0	\N	Station 1 - Vehicle No: 002-12 - Engine is damaged from the overheating	2026-01-21 10:39:42	2026-01-21 10:39:42
26	R 1	\N	Ford	Rescue	2016	Out of Service	0	\N	Station 1 - Vehicle No: 16508 - Rear AC failure / Subfloor repairs	2026-01-21 10:39:42	2026-01-21 10:39:42
27	R 11	\N	Ford	Rescue	2019	In Service	0	\N	Station 1 - Vehicle No: 19502	2026-01-21 10:39:42	2026-01-21 10:39:42
28	A 1	\N	Pierce	Air Truck	2002	In Service	0	\N	Station 2 - Vehicle No: 002-20	2026-01-21 10:39:42	2026-01-21 10:39:42
29	A 2	\N	Pierce	Air Truck	2018	In Service	0	\N	Station 2 - Vehicle No: 18500	2026-01-21 10:39:42	2026-01-21 10:39:42
30	E 2	\N	Pierce	Engine	2024	In Service	0	\N	Station 2 - Vehicle No: 24509	2026-01-21 10:39:42	2026-01-21 10:39:42
31	R 2	\N	Ford	Rescue	2016	In Service	0	\N	Station 2 - Vehicle No: 16507	2026-01-21 10:39:42	2026-01-21 10:39:42
32	R 22	\N	Ford	Rescue	2019	In Service	0	\N	Station 2 - Vehicle No: 19503	2026-01-21 10:39:42	2026-01-21 10:39:42
33	E 3	\N	Pierce	Engine	2002	In Service	0	\N	Station 3 - Vehicle No: 002-22 - Pump has been re ordered	2026-01-21 10:39:42	2026-01-21 10:39:42
34	L 3	\N	Pierce	Ladder	2017	In Service	0	\N	Station 3 - Vehicle No: 17505	2026-01-21 10:39:42	2026-01-21 10:39:42
35	R 3	\N	Ford	Rescue	2017	In Service	0	\N	Station 3 - Vehicle No: 17501	2026-01-21 10:39:42	2026-01-21 10:39:42
36	R 44	\N	Ford	Rescue	2017	In Service	0	\N	Station 4 - Vehicle No: 17503	2026-01-21 10:39:42	2026-01-21 10:39:42
37	E 4	\N	Pierce	Engine	2020	In Service	0	\N	Station 4 - Vehicle No: 20504	2026-01-21 10:39:42	2026-01-21 10:39:42
38	R 4	\N	Ford	Rescue	2017	In Service	0	\N	Station 4 - Vehicle No: 17502	2026-01-21 10:39:42	2026-01-21 10:39:42
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.cache (key, value, expiration) FROM stdin;
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
framework/schedule-6f50a2be3ff5d69f18329ab66d4d9f24963245242200	QH4dVghWiyz9ZYLI	1769036401
\.


--
-- Data for Name: capital_projects; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.capital_projects (id, name, description, budget_amount, start_date, target_completion_date, actual_completion, notes, created_at, updated_at, project_number, status, priority, ai_priority_rank, ai_priority_score, ai_reasoning, last_ai_analysis) FROM stdin;
3	FIRE STATION #4 – REPL. EXHAUST SYS	Replacement of vehicle exhaust system at Fire Station #4 to improve air quality and safety. Includes removal of old system, installation of new exhaust extraction equipment, and testing.	22946.00	2026-02-04	2026-07-21	\N	\N	2026-01-21 14:43:19	2026-01-21 14:43:19	66727	pending	medium	\N	\N	\N	\N
4	FIRE STATION #1 – REPL. EXHAUST SYS	Complete replacement of the vehicle exhaust extraction system at Fire Station #1. This is a high-priority project to ensure firefighter health and safety by eliminating diesel exhaust exposure in the apparatus bay.	285000.00	2026-02-21	2026-10-21	\N	\N	2026-01-21 14:43:19	2026-01-21 14:43:19	67927	pending	high	\N	\N	\N	\N
5	FIRE STATION #2 – RESTROOM/PLUMBING	Major renovation of restroom facilities and plumbing infrastructure at Fire Station #2. Includes replacement of aging pipes, fixtures, ADA-compliant upgrades, and modernization of facilities.	255000.00	2026-02-11	2026-11-21	\N	\N	2026-01-21 14:43:19	2026-01-21 14:43:19	63631	pending	high	\N	\N	\N	\N
6	FIRE STATION #4 – ROOF REPLACEMENT	Critical roof replacement project for Fire Station #4. The existing roof has reached end of life and requires complete replacement to prevent water damage and maintain structural integrity. Highest priority infrastructure project.	357000.00	2026-01-21	2027-01-21	\N	\N	2026-01-21 14:43:19	2026-01-21 14:43:19	63731	pending	critical	\N	\N	\N	\N
7	FIRE STATION #2 – REPL. EXHAUST SYS	Replacement of vehicle exhaust extraction system at Fire Station #2. Part of department-wide initiative to upgrade all station exhaust systems for improved air quality and firefighter health.	200000.00	2026-03-21	2026-09-21	\N	\N	2026-01-21 14:43:19	2026-01-21 14:43:19	65127	pending	medium	\N	\N	\N	\N
8	FIRE STATION #3 – REPL. EXHAUST SYS	Replacement of vehicle exhaust extraction system at Fire Station #3. This project will eliminate diesel exhaust exposure in the apparatus bay and improve overall air quality for personnel.	228000.00	2026-03-21	2026-09-21	\N	\N	2026-01-21 14:43:19	2026-01-21 14:43:19	66527	pending	medium	\N	\N	\N	\N
9	FIRE STATION #4 – REPL. EXHAUST SYS	Secondary exhaust system replacement project for Fire Station #4 apparatus bay expansion. Complements the initial exhaust system project to cover additional bays.	177054.00	2026-04-21	2026-10-21	\N	\N	2026-01-21 14:43:19	2026-01-21 14:43:19	66727-B	pending	medium	\N	\N	\N	\N
10	FIRE STATION #2 – VEHICLE AWNING REPL	Replacement of vehicle awning structure at Fire Station #2. The existing awning provides weather protection for apparatus and personnel during vehicle operations. Project includes structural improvements and modern materials.	237357.00	2026-02-21	2026-11-21	\N	\N	2026-01-21 14:43:19	2026-01-21 14:43:19	60626	pending	high	\N	\N	\N	\N
\.


--
-- Data for Name: equipment_items; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.equipment_items (id, name, normalized_name, category, description, manufacturer, unit_of_measure, reorder_min, reorder_max, location_id, is_active, created_at, updated_at) FROM stdin;
2	Mounts	mounts	\N	\N	\N	each	0	\N	3	t	2026-01-22 01:20:34	2026-01-22 01:20:34
3	Aerial Master Stream Tips	aerial master stream tips	\N	\N	\N	each	0	\N	3	t	2026-01-22 01:20:34	2026-01-22 01:20:34
4	Stream Straightener	stream straightener	\N	\N	\N	each	0	\N	3	t	2026-01-22 01:20:34	2026-01-22 01:20:34
5	Nozzle Teeth Packs	nozzle teeth packs	\N	\N	\N	each	0	\N	3	t	2026-01-22 01:20:34	2026-01-22 01:20:34
6	Stortz Caps	stortz caps	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
7	4” Cap	4 cap	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
8	5” Caps	5 caps	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
9	6” Caps	6 caps	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
10	6” Gaskets	6 gaskets	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
11	5” Gaskets	5 gaskets	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
12	5” Suction Gaskets	5 suction gaskets	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
13	2 1/2” Gaskets	2 12 gaskets	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
14	1 1/2” Gaskets	1 12 gaskets	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
15	Misc. Gaskets	misc gaskets	\N	\N	\N	each	0	\N	4	t	2026-01-22 01:20:34	2026-01-22 01:20:34
16	6” to 4” Reducers	6 to 4 reducers	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
17	6” to 2” Reducer	6 to 2 reducer	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
18	4” to 2 1/2” Reducers	4 to 2 12 reducers	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
19	Stortz Connection with 4” Male	stortz connection with 4 male	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
20	Stortz Connection with 5” Male	stortz connection with 5 male	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
21	Stortz Connection with 6” Male	stortz connection with 6 male	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
22	Stortz Connection with 6” Female	stortz connection with 6 female	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
23	5” to 4” Reducers	5 to 4 reducers	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
24	4 1/2” Adapter	4 12 adapter	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
25	Stortz Connection with 4” Female	stortz connection with 4 female	\N	\N	\N	each	0	\N	5	t	2026-01-22 01:20:34	2026-01-22 01:20:34
26	Hydrant Assist Valve	hydrant assist valve	\N	\N	\N	each	0	\N	6	t	2026-01-22 01:20:34	2026-01-22 01:20:34
27	Intake	intake	\N	\N	\N	each	0	\N	6	t	2026-01-22 01:20:34	2026-01-22 01:20:34
28	Stortz Elbow to 4” Female	stortz elbow to 4 female	\N	\N	\N	each	0	\N	6	t	2026-01-22 01:20:34	2026-01-22 01:20:34
29	Misc. Adapters	misc adapters	\N	\N	\N	each	0	\N	6	t	2026-01-22 01:20:34	2026-01-22 01:20:34
30	Foam Boot	foam boot	\N	\N	\N	each	0	\N	7	t	2026-01-22 01:20:34	2026-01-22 01:20:34
31	75psi 175gpm Fog Tips	75psi 175gpm fog tips	\N	\N	\N	each	0	\N	8	t	2026-01-22 01:20:34	2026-01-22 01:20:34
32	100psi 325gpm Fog Tips	100psi 325gpm fog tips	\N	\N	\N	each	0	\N	8	t	2026-01-22 01:20:34	2026-01-22 01:20:34
33	75psi 200gpm Fog Tips	75psi 200gpm fog tips	\N	\N	\N	each	0	\N	8	t	2026-01-22 01:20:34	2026-01-22 01:20:34
34	Selectomatic Nozzle Tip	selectomatic nozzle tip	\N	\N	\N	each	0	\N	8	t	2026-01-22 01:20:34	2026-01-22 01:20:34
35	Other Fog Tips	other fog tips	\N	\N	\N	each	0	\N	8	t	2026-01-22 01:20:34	2026-01-22 01:20:34
36	Glow in the Dark Stream Adjusters	glow in the dark stream adjusters	\N	\N	\N	each	0	\N	8	t	2026-01-22 01:20:34	2026-01-22 01:20:34
37	Bag of Brass Set Screws	bag of brass set screws	\N	\N	\N	each	0	\N	8	t	2026-01-22 01:20:34	2026-01-22 01:20:34
38	Red Box Misc.	red box misc	\N	\N	\N	each	0	\N	8	t	2026-01-22 01:20:34	2026-01-22 01:20:34
39	Appliance Mounts	appliance mounts	\N	\N	\N	each	0	\N	8	t	2026-01-22 01:20:34	2026-01-22 01:20:34
40	Handle Playpipes	handle playpipes	\N	\N	\N	each	0	\N	9	t	2026-01-22 01:20:34	2026-01-22 01:20:34
41	Incline Gates	incline gates	\N	\N	\N	each	0	\N	9	t	2026-01-22 01:20:34	2026-01-22 01:20:34
42	1” Breakaways Bails	1 breakaways bails	\N	\N	\N	each	0	\N	9	t	2026-01-22 01:20:34	2026-01-22 01:20:34
43	1 1/2” Breakaway Bails	1 12 breakaway bails	\N	\N	\N	each	0	\N	9	t	2026-01-22 01:20:34	2026-01-22 01:20:34
44	Water Thiefs	water thiefs	\N	\N	\N	each	0	\N	10	t	2026-01-22 01:20:34	2026-01-22 01:20:34
45	Ground Y Supply	ground y supply	\N	\N	\N	each	0	\N	10	t	2026-01-22 01:20:34	2026-01-22 01:20:34
46	Ground Supply	ground supply	\N	\N	\N	each	0	\N	10	t	2026-01-22 01:20:34	2026-01-22 01:20:34
47	Blitzfire	blitzfire	\N	\N	\N	each	0	\N	11	t	2026-01-22 01:20:34	2026-01-22 01:20:34
48	Strainers	strainers	\N	\N	\N	each	0	\N	11	t	2026-01-22 01:20:34	2026-01-22 01:20:34
49	Hose Edge Protectors	hose edge protectors	\N	\N	\N	each	0	\N	11	t	2026-01-22 01:20:34	2026-01-22 01:20:34
50	1 1/4” Nozzle Tips	1 14 nozzle tips	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
51	1” Nozzle Tip	1 nozzle tip	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
52	1 3/8” Nozzle Tips	1 38 nozzle tips	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
53	1 1/2” Nozzle Tip	1 12 nozzle tip	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
54	1 3/4” Nozzle Tip	1 34 nozzle tip	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
55	2” Nozzle Tip	2 nozzle tip	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
56	1 1/8” Nozzle Tip	1 18 nozzle tip	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
57	2 1/2” Elbows	2 12 elbows	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
58	1 1/2” Double Males	1 12 double males	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
59	1 1/2” Couplings	1 12 couplings	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
60	2 1/2 Cap Pressure Gauge	2 12 cap pressure gauge	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
61	Inline Pressure Gauge	inline pressure gauge	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
62	2 1/2” to 1/2” Reducer	2 12 to 12 reducer	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
63	2 1/2” Female Caps	2 12 female caps	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
64	2 1/2” Male Caps	2 12 male caps	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
65	1 1/2” Female Caps	1 12 female caps	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
66	2 1/2 to 1” Reducers	2 12 to 1 reducers	\N	\N	\N	each	0	\N	12	t	2026-01-22 01:20:34	2026-01-22 01:20:34
67	Gated Wye	gated wye	\N	\N	\N	each	0	\N	13	t	2026-01-22 01:20:34	2026-01-22 01:20:34
68	Gate Valves	gate valves	\N	\N	\N	each	0	\N	13	t	2026-01-22 01:20:34	2026-01-22 01:20:34
69	Double Male 2 1/2”	double male 2 12	\N	\N	\N	each	0	\N	13	t	2026-01-22 01:20:34	2026-01-22 01:20:34
70	Double Female 2 1/2”	double female 2 12	\N	\N	\N	each	0	\N	13	t	2026-01-22 01:20:34	2026-01-22 01:20:34
71	2 1/2” Couplings	2 12 couplings	\N	\N	\N	each	0	\N	13	t	2026-01-22 01:20:34	2026-01-22 01:20:34
72	Siamese 2.5" with clapper valves	siamese 25 with clapper valves	\N	\N	Akron	each	0	\N	14	t	2026-01-22 01:20:34	2026-01-22 01:20:34
73	Siamese with 5" storz connection	siamese with 5 storz connection	\N	\N	\N	each	0	\N	14	t	2026-01-22 01:20:34	2026-01-22 01:20:34
74	Trimese 2.5"	trimese 25	\N	\N	\N	each	0	\N	14	t	2026-01-22 01:20:34	2026-01-22 01:20:34
75	Wye 2.5"	wye 25	\N	\N	\N	each	0	\N	14	t	2026-01-22 01:20:34	2026-01-22 01:20:34
76	Hose Jacket	hose jacket	\N	\N	\N	each	0	\N	14	t	2026-01-22 01:20:34	2026-01-22 01:20:34
77	Foam Pick up tubes	foam pick up tubes	\N	\N	\N	each	0	\N	14	t	2026-01-22 01:20:34	2026-01-22 01:20:34
78	Turbo draft (small)	turbo draft small	\N	\N	\N	each	0	\N	14	t	2026-01-22 01:20:34	2026-01-22 01:20:34
79	Drafting appliances	drafting appliances	\N	\N	\N	each	0	\N	14	t	2026-01-22 01:20:34	2026-01-22 01:20:34
80	Training Foam	training foam	\N	\N	\N	each	0	\N	15	t	2026-01-22 01:20:34	2026-01-22 01:20:34
81	Auto Wash (1)	auto wash 1	\N	\N	\N	each	0	\N	15	t	2026-01-22 01:20:34	2026-01-22 01:20:34
82	Fog Fluid (x2)	fog fluid x2	\N	\N	\N	each	0	\N	15	t	2026-01-22 01:20:34	2026-01-22 01:20:34
83	TK Charger (x5)	tk charger x5	\N	\N	\N	each	0	\N	15	t	2026-01-22 01:20:34	2026-01-22 01:20:34
84	Vector Fog Machine (x3)	vector fog machine x3	\N	\N	\N	each	0	\N	15	t	2026-01-22 01:20:34	2026-01-22 01:20:34
85	Marq Fog Machine (x2)	marq fog machine x2	\N	\N	\N	each	0	\N	15	t	2026-01-22 01:20:34	2026-01-22 01:20:34
86	4” PVC Pipe (x4)	4 pvc pipe x4	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
87	8” PVC Pipe (x1)	8 pvc pipe x1	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
88	Sprinkler Wedge (x7)	sprinkler wedge x7	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
89	Pipe Clamp (x4)	pipe clamp x4	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
90	Male/Female Threaded PVC Caps 1” - 3/4” (x 1 bag)	malefemale threaded pvc caps 1  34 x 1 bag	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
91	Glue on PVC Caps (x 5)	glue on pvc caps x 5	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
92	Cone Pipe Plug (x1)	cone pipe plug x1	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
93	Well Test (x1)	well test x1	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
94	Crowbar (x5)	crowbar x5	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
95	Ball-peen Hammer (x1)	ballpeen hammer x1	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
96	Hammer (x1)	hammer x1	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
97	511 Tool (x1)	511 tool x1	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
98	Spanner Wrench (x1)	spanner wrench x1	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
99	Assortment of Allen Wrenches (x1)	assortment of allen wrenches x1	\N	\N	\N	each	0	\N	16	t	2026-01-22 01:20:34	2026-01-22 01:20:34
100	Decon System (x25)	decon system x25	\N	\N	\N	each	0	\N	17	t	2026-01-22 01:20:34	2026-01-22 01:20:34
101	Blankets (x4)	blankets x4	\N	\N	\N	each	0	\N	17	t	2026-01-22 01:20:34	2026-01-22 01:20:34
102	Duffle Bag (x5)	duffle bag x5	\N	\N	\N	each	0	\N	17	t	2026-01-22 01:20:34	2026-01-22 01:20:34
103	Struts with Attachments (x8)	struts with attachments x8	\N	\N	\N	each	0	\N	18	t	2026-01-22 01:20:34	2026-01-22 01:20:34
104	Tool box	tool box	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:34	2026-01-22 01:20:34
105	AAA Batteries	aaa batteries	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:34	2026-01-22 01:20:34
106	AA Batteries	aa batteries	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
107	D Battery	d battery	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
108	Allen Wrench Set Metric	allen wrench set metric	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
109	Allen Wrench Set SAE	allen wrench set sae	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
110	Allen Wrench Set Metric/SAE	allen wrench set metricsae	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
111	Box Cutter	box cutter	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
112	Frangible Bulb Sprinkler Head	frangible bulb sprinkler head	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
113	Flat-headed Screwdriver	flatheaded screwdriver	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
114	Philips Screwdriver	philips screwdriver	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
115	Mini Philips Screwdriver	mini philips screwdriver	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
116	Torx Screwdriver	torx screwdriver	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
117	Lockout	lockout	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
118	Open-ended Wrench	openended wrench	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
119	Slip-Joint Pliers	slipjoint pliers	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
120	Dyke Cutters	dyke cutters	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
121	Mini Hacksaw	mini hacksaw	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
122	Vice Grips	vice grips	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
123	Adjustable Wrench	adjustable wrench	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
124	Wire Cutter	wire cutter	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
126	Hammer	hammer	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
127	Pipe-Wrench	pipewrench	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
128	Air Cutting Chisel	air cutting chisel	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
129	20in Chainsaw Blade	20in chainsaw blade	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
130	Chainsaw Chain	chainsaw chain	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
131	9in Sawzaw Blade	9in sawzaw blade	\N	\N	\N	box	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
132	Dremel	dremel	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
133	12in Hacksaw Blade	12in hacksaw blade	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
134	Carbide Sawzaw Blade	carbide sawzaw blade	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
135	Air Lube	air lube	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
136	4-6in Spanner Wrench	46in spanner wrench	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
137	5in Spanner Wrench	5in spanner wrench	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
138	Smoke Trainer	smoke trainer	\N	\N	\N	box	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
139	Come-along	comealong	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
140	Come-along Bar	comealong bar	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
141	Rope Edge Protection	rope edge protection	\N	\N	\N	each	0	\N	19	t	2026-01-22 01:20:35	2026-01-22 01:20:35
142	Dewalt Carrying Bag	dewalt carrying bag	\N	\N	\N	each	0	\N	20	t	2026-01-22 01:20:35	2026-01-22 01:20:35
143	Hydraram	hydraram	\N	\N	\N	each	0	\N	20	t	2026-01-22 01:20:35	2026-01-22 01:20:35
144	Rescue Tech Bag	rescue tech bag	\N	\N	\N	each	0	\N	20	t	2026-01-22 01:20:35	2026-01-22 01:20:35
145	Chainsaw Safety Chap	chainsaw safety chap	\N	\N	\N	box	0	\N	21	t	2026-01-22 01:20:35	2026-01-22 01:20:35
146	Yates	yates	\N	\N	\N	each	0	\N	21	t	2026-01-22 01:20:35	2026-01-22 01:20:35
147	Frisbees	frisbees	\N	\N	\N	box	0	\N	21	t	2026-01-22 01:20:35	2026-01-22 01:20:35
148	12x15 4-mil Clear Bags	12x15 4mil clear bags	\N	\N	\N	box	0	\N	21	t	2026-01-22 01:20:35	2026-01-22 01:20:35
149	Big Easy Carrying Bag	big easy carrying bag	\N	\N	\N	each	0	\N	21	t	2026-01-22 01:20:35	2026-01-22 01:20:35
150	Pop-up Traffic Cone with Carrying Bag	popup traffic cone with carrying bag	\N	\N	\N	each	0	\N	21	t	2026-01-22 01:20:35	2026-01-22 01:20:35
151	Universal Lockout Tool Set	universal lockout tool set	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
152	Air Wedge	air wedge	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
153	Glassmaster	glassmaster	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
154	K-Tool	ktool	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
155	Search and Rescue Gloves	search and rescue gloves	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
156	Flag Pole Mount	flag pole mount	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
157	Conversion Kit	conversion kit	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
158	Spring Rope Hook	spring rope hook	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
159	Wedge Pack	wedge pack	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
160	Cooler Cable	cooler cable	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
161	Access Tool	access tool	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
162	Access Tool Kit	access tool kit	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
163	Cutters Edge Tool Sling	cutters edge tool sling	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
164	Hot Stick	hot stick	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
165	AC Voltage Detector	ac voltage detector	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
166	Red Case with Steel Rods	red case with steel rods	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
167	Access Tool Bag	access tool bag	\N	\N	\N	each	0	\N	22	t	2026-01-22 01:20:35	2026-01-22 01:20:35
168	Pick Headed Axe	pick headed axe	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
169	Flat Headed Axe	flat headed axe	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
170	Sledge Hammer	sledge hammer	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
171	Mini Sledge	mini sledge	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
172	Rubber Mallet	rubber mallet	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
125	Crowbar	crowbar	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
173	Style-50 Bar	style50 bar	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
174	Mini Shovel	mini shovel	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
175	Mini Halligan	mini halligan	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
176	Storm Drain Tool	storm drain tool	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
177	Hacksaw	hacksaw	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
178	Quick Strap Mounting System	quick strap mounting system	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
179	Box of Forcible Entry Tool Straps	box of forcible entry tool straps	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
180	36in Bolt Cutters	36in bolt cutters	\N	\N	\N	each	0	\N	23	t	2026-01-22 01:20:35	2026-01-22 01:20:35
181	2 Sided Spannered Hydrant Wrench	2 sided spannered hydrant wrench	\N	\N	\N	each	0	\N	24	t	2026-01-22 01:20:35	2026-01-22 01:20:35
182	1 Sided Spannered Hydrant Wrench	1 sided spannered hydrant wrench	\N	\N	\N	each	0	\N	24	t	2026-01-22 01:20:35	2026-01-22 01:20:35
183	Hydrant Wrench	hydrant wrench	\N	\N	\N	each	0	\N	24	t	2026-01-22 01:20:35	2026-01-22 01:20:35
184	FLIR TIC Case	flir tic case	\N	\N	\N	each	0	\N	24	t	2026-01-22 01:20:35	2026-01-22 01:20:35
185	Carpenter Square	carpenter square	\N	\N	\N	each	0	\N	24	t	2026-01-22 01:20:35	2026-01-22 01:20:35
186	Keiser Deadblow 10lb	keiser deadblow 10lb	\N	\N	\N	each	0	\N	24	t	2026-01-22 01:20:35	2026-01-22 01:20:35
187	Sprinkler Assortment in Ammo Can	sprinkler assortment in ammo can	\N	\N	\N	each	0	\N	24	t	2026-01-22 01:20:35	2026-01-22 01:20:35
188	Water Can	water can	\N	\N	\N	each	0	\N	24	t	2026-01-22 01:20:35	2026-01-22 01:20:35
189	CO2 Can	co2 can	\N	\N	\N	each	0	\N	24	t	2026-01-22 01:20:35	2026-01-22 01:20:35
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
3	Supply Closet	A	1	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
4	Supply Closet	A	2	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
5	Supply Closet	A	3	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
6	Supply Closet	A	4	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
7	Supply Closet	B	1	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
8	Supply Closet	B	2	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
9	Supply Closet	B	3	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
10	Supply Closet	B	4	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
11	Supply Closet	C	1	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
12	Supply Closet	C	2	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
13	Supply Closet	C	3	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
14		C	4	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
15	Supply Closet	D	4	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
16	Supply Closet	D	3	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
17	Supply Closet	E	1	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
18	Supply Closet	E	2	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
19	Supply Closet	E	3	\N	Imported from CSV	2026-01-22 01:20:34	2026-01-22 01:20:34
20	Supply Closet	E	4	\N	Imported from CSV	2026-01-22 01:20:35	2026-01-22 01:20:35
21	Supply Closet	F	1	\N	Imported from CSV	2026-01-22 01:20:35	2026-01-22 01:20:35
22	Supply Closet	F	2	\N	Imported from CSV	2026-01-22 01:20:35	2026-01-22 01:20:35
23	Supply Closet	F	3	\N	Imported from CSV	2026-01-22 01:20:35	2026-01-22 01:20:35
24	Supply Closet	F	4	\N	Imported from CSV	2026-01-22 01:20:35	2026-01-22 01:20:35
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
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	0001_01_01_000002_create_jobs_table	1
4	2026_01_20_170835_create_apparatuses_table	2
5	2026_01_20_170835_create_stations_table	2
6	2026_01_20_170836_create_capital_projects_table	2
7	2026_01_20_170836_create_uniforms_table	2
8	2026_01_20_170837_create_shop_works_table	2
9	2026_01_20_210149_create_notifications_table	3
10	2026_01_20_213439_alter_notifications_data_to_jsonb	4
11	2026_01_21_000002_create_apparatus_inspections_table	5
12	2026_01_21_000003_create_apparatus_defects_table	5
18	2026_01_21_142803_alter_capital_projects_table_for_ai_tracking	6
19	2026_01_21_142809_create_project_milestones_table	6
20	2026_01_21_142810_create_project_updates_table	6
21	2026_01_21_142811_create_notification_tracking_table	6
22	2026_01_21_142812_create_ai_analysis_logs_table	6
23	2026_01_22_000000_create_stock_mutations_table	7
24	2026_01_22_000001_create_inventory_locations_table	7
25	2026_01_22_000002_create_equipment_items_table	7
26	2026_01_22_000003_create_apparatus_defect_recommendations_table	7
27	2026_01_22_000004_create_apparatus_inventory_allocations_table	7
28	2026_01_22_000005_create_admin_alert_events_table	7
29	2026_01_22_000006_create_import_runs_table	7
30	2026_01_22_000007_enable_pg_trgm_extension	7
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
-- Data for Name: project_milestones; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.project_milestones (id, capital_project_id, title, description, due_date, completed, completed_at, created_at, updated_at) FROM stdin;
1	4	Design Phase Complete	Architectural and engineering designs finalized and approved.	2026-04-21	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
2	4	Permits Obtained	All necessary building permits and approvals secured.	2026-04-21	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
3	4	Construction Started	Construction phase begins with contractor mobilization.	2026-05-21	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
4	4	Final Inspection	Final walkthrough and inspection completed, punch list items addressed.	2026-09-21	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
5	5	Design Phase Complete	Architectural and engineering designs finalized and approved.	2026-04-11	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
6	5	Permits Obtained	All necessary building permits and approvals secured.	2026-05-11	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
7	5	Construction Started	Construction phase begins with contractor mobilization.	2026-06-11	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
8	5	Final Inspection	Final walkthrough and inspection completed, punch list items addressed.	2026-10-11	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
9	6	Design Phase Complete	Architectural and engineering designs finalized and approved.	2026-03-21	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
10	6	Permits Obtained	All necessary building permits and approvals secured.	2026-05-21	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
11	6	Construction Started	Construction phase begins with contractor mobilization.	2026-06-21	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
12	6	Final Inspection	Final walkthrough and inspection completed, punch list items addressed.	2026-12-21	f	\N	2026-01-21 14:43:19	2026-01-21 14:43:19
\.


--
-- Data for Name: project_updates; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.project_updates (id, capital_project_id, user_id, update_text, created_at) FROM stdin;
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
MCUYzjRrUqQRDrRHbfYggImIhmkTEQ814ICmjJlx	\N	20.169.73.43	Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot	YTo0OntzOjY6Il90b2tlbiI7czo0MDoiSEtoZXI0a2tGS3Q4T01RVU9JQmhRRFhNV01GVjZSOUNYRmh5RWplYyI7czozOiJ1cmwiO2E6MTp7czo4OiJpbnRlbmRlZCI7czozNjoiaHR0cHM6Ly9zdXBwb3J0LmRhcmxleXBsZXguY29tL2FkbWluIjt9czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MzY6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbS9hZG1pbiI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1769106544
kAblsJkDgalHS0CVY8InHRWTPDxfvNRwcwf8pfxV	\N	172.23.0.1	curl/8.5.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoiblVaTjFiWEJQMEhQbmljeFhQaGt3eGJFVFNFMTZwbTd4RWdxYmtKMCI7czozOiJ1cmwiO2E6MTp7czo4OiJpbnRlbmRlZCI7czoyMjoiaHR0cHM6Ly9sb2NhbGhvc3Q6ODA4MiI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1769105715
U3lUhBVjN6Yr8Dh6bdIpTWQyPaCD5Dw3eQkS5s0V	\N	145.223.73.170	curl/8.5.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoiUVNnSU83VFYwYjJvVDVBWGd6bEQxMTEwOHh5cG5kdW16R0Y5djlWMSI7czozOiJ1cmwiO2E6MTp7czo4OiJpbnRlbmRlZCI7czozMDoiaHR0cHM6Ly9zdXBwb3J0LmRhcmxleXBsZXguY29tIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769107637
FpbJZW60QQnaX0oOPfYB2RLAQ0k2X6vovV8sLLpU	\N	20.169.73.43	Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot	YTozOntzOjY6Il90b2tlbiI7czo0MDoiNXVMT2R3VTZhT0w0d1F5U2Z1azNIS3lUemY1RDJoVmloQWowN0l0cyI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6NDI6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbS9hZG1pbi9sb2dpbiI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1769106544
hsTvqaWTvNaCT08Sfh5e1OS3RI9qDbulCVoj6ldO	\N	8.21.220.30	Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36	YTo0OntzOjY6Il90b2tlbiI7czo0MDoiaGd5enpGbXZicHdyNVVobEZUZVhwY1N6WXNSQ3h2UkVhY3lkQWFiUSI7czozOiJ1cmwiO2E6MTp7czo4OiJpbnRlbmRlZCI7czozNjoiaHR0cHM6Ly9zdXBwb3J0LmRhcmxleXBsZXguY29tL2FkbWluIjt9czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6NDI6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbS9hZG1pbi9sb2dpbiI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1769105759
nj1BVsnZIuHYzVoMq2Mgl5t5WX00efZOCONdwjI0	\N	8.21.220.30	curl/8.16.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoiT0Facnl2YWFNbk9NMFc2d1dLOUVGS1ZvVFpabnJDWXZsZlNjQUJsaiI7czozOiJ1cmwiO2E6MTp7czo4OiJpbnRlbmRlZCI7czozMDoiaHR0cHM6Ly9zdXBwb3J0LmRhcmxleXBsZXguY29tIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769110870
HHKsJmqFm8qNJTlsZr04Dc3veHpc5VRBRENzgJyb	\N	145.223.73.170	curl/8.5.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoiclhQa3VsejlNbDZLU1VIYWY0a0R0ZGJGbVBjQ090MkpMYm1xZzlsOSI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6NDI6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbS9hZG1pbi9sb2dpbiI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1769107305
ZDTg9ZGKi3t2iJztGU7i0l9KvbqTaw2LBFBw0xbk	\N	145.223.73.170	curl/8.5.0	YToyOntzOjY6Il90b2tlbiI7czo0MDoiVkJieXZyV1Bndk43TEIwRWVXWndYOWdvWFdva1lqRDlNNVJDYUJVWSI7czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769103672
vsKt8lvyaYycZ1Wwj4jaGuHYsnwbcW3NXgg9s7eQ	2	8.21.220.30	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:147.0) Gecko/20100101 Firefox/147.0	YTo1OntzOjY6Il90b2tlbiI7czo0MDoiYkVIczllUjJFNFdXU3BTQ1hDRG1jY0F6dEd0aTNST0t2Q3NwTlZFTyI7czo1MDoibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MjtzOjE3OiJwYXNzd29yZF9oYXNoX3dlYiI7czo2MDoiJDJ5JDEyJHgxc1F4OWhYV0ZDVGVwL2MvM1JKTS4uR2NxSWNPYUs1Lkg5ZlhHMjdUdC5FcUZLZjZzQXVDIjtzOjk6Il9wcmV2aW91cyI7YToxOntzOjM6InVybCI7czozNjoiaHR0cHM6Ly9zdXBwb3J0LmRhcmxleXBsZXguY29tL2FkbWluIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769112530
OrcPqRGNLbsuHSmvz7tjh7zHgSENbD91ontm51ls	\N	8.21.220.30	curl/8.16.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoiUVFVOWp3TnZJMXFpUWd6aktmdVRkaERLOGhPcTh0b3BQSVl0VEVUSiI7czozOiJ1cmwiO2E6MTp7czo4OiJpbnRlbmRlZCI7czozMDoiaHR0cHM6Ly9zdXBwb3J0LmRhcmxleXBsZXguY29tIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1769107503
\.


--
-- Data for Name: shop_works; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.shop_works (id, project_name, description, apparatus_id, status, parts_list, estimated_cost, actual_cost, started_date, completed_date, assigned_to, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: stations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.stations (id, station_number, address, city, state, zip_code, captain_in_charge, phone, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: stock_mutations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.stock_mutations (id, stockable_type, stockable_id, reference, amount, description, created_at, updated_at) FROM stdin;
1	App\\Models\\EquipmentItem	2	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
2	App\\Models\\EquipmentItem	3	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
3	App\\Models\\EquipmentItem	4	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
4	App\\Models\\EquipmentItem	5	\N	17	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
5	App\\Models\\EquipmentItem	6	\N	6	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
6	App\\Models\\EquipmentItem	7	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
7	App\\Models\\EquipmentItem	8	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
8	App\\Models\\EquipmentItem	9	\N	8	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
9	App\\Models\\EquipmentItem	10	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
10	App\\Models\\EquipmentItem	11	\N	16	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
11	App\\Models\\EquipmentItem	12	\N	10	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
12	App\\Models\\EquipmentItem	13	\N	18	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
13	App\\Models\\EquipmentItem	14	\N	9	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
14	App\\Models\\EquipmentItem	15	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
15	App\\Models\\EquipmentItem	16	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
16	App\\Models\\EquipmentItem	17	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
17	App\\Models\\EquipmentItem	18	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
18	App\\Models\\EquipmentItem	19	\N	6	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
19	App\\Models\\EquipmentItem	20	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
20	App\\Models\\EquipmentItem	21	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
21	App\\Models\\EquipmentItem	22	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
22	App\\Models\\EquipmentItem	23	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
23	App\\Models\\EquipmentItem	24	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
24	App\\Models\\EquipmentItem	25	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
25	App\\Models\\EquipmentItem	26	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
26	App\\Models\\EquipmentItem	27	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
27	App\\Models\\EquipmentItem	28	\N	5	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
28	App\\Models\\EquipmentItem	29	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
29	App\\Models\\EquipmentItem	30	\N	8	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
30	App\\Models\\EquipmentItem	31	\N	10	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
31	App\\Models\\EquipmentItem	32	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
32	App\\Models\\EquipmentItem	33	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
33	App\\Models\\EquipmentItem	34	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
34	App\\Models\\EquipmentItem	35	\N	5	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
35	App\\Models\\EquipmentItem	36	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
36	App\\Models\\EquipmentItem	37	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
37	App\\Models\\EquipmentItem	38	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
38	App\\Models\\EquipmentItem	39	\N	9	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
39	App\\Models\\EquipmentItem	40	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
40	App\\Models\\EquipmentItem	41	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
41	App\\Models\\EquipmentItem	42	\N	6	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
42	App\\Models\\EquipmentItem	43	\N	6	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
43	App\\Models\\EquipmentItem	44	\N	6	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
44	App\\Models\\EquipmentItem	45	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
45	App\\Models\\EquipmentItem	46	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
46	App\\Models\\EquipmentItem	47	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
47	App\\Models\\EquipmentItem	48	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
48	App\\Models\\EquipmentItem	49	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
49	App\\Models\\EquipmentItem	50	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
50	App\\Models\\EquipmentItem	51	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
51	App\\Models\\EquipmentItem	52	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
52	App\\Models\\EquipmentItem	53	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
53	App\\Models\\EquipmentItem	54	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
54	App\\Models\\EquipmentItem	55	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
55	App\\Models\\EquipmentItem	56	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
56	App\\Models\\EquipmentItem	57	\N	7	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
57	App\\Models\\EquipmentItem	58	\N	9	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
58	App\\Models\\EquipmentItem	59	\N	7	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
59	App\\Models\\EquipmentItem	60	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
60	App\\Models\\EquipmentItem	61	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
61	App\\Models\\EquipmentItem	62	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
62	App\\Models\\EquipmentItem	63	\N	5	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
63	App\\Models\\EquipmentItem	64	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
64	App\\Models\\EquipmentItem	65	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
65	App\\Models\\EquipmentItem	66	\N	12	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
66	App\\Models\\EquipmentItem	67	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
67	App\\Models\\EquipmentItem	68	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
68	App\\Models\\EquipmentItem	69	\N	21	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
69	App\\Models\\EquipmentItem	70	\N	15	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
70	App\\Models\\EquipmentItem	71	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
71	App\\Models\\EquipmentItem	72	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
72	App\\Models\\EquipmentItem	73	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
73	App\\Models\\EquipmentItem	74	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
74	App\\Models\\EquipmentItem	75	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
75	App\\Models\\EquipmentItem	76	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
76	App\\Models\\EquipmentItem	77	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
77	App\\Models\\EquipmentItem	78	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
78	App\\Models\\EquipmentItem	79	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
79	App\\Models\\EquipmentItem	80	\N	5	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
80	App\\Models\\EquipmentItem	81	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
81	App\\Models\\EquipmentItem	82	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
82	App\\Models\\EquipmentItem	83	\N	5	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
83	App\\Models\\EquipmentItem	84	\N	3	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
84	App\\Models\\EquipmentItem	85	\N	2	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
85	App\\Models\\EquipmentItem	86	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
86	App\\Models\\EquipmentItem	87	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
87	App\\Models\\EquipmentItem	88	\N	7	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
88	App\\Models\\EquipmentItem	89	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
89	App\\Models\\EquipmentItem	90	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
90	App\\Models\\EquipmentItem	91	\N	5	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
91	App\\Models\\EquipmentItem	92	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
92	App\\Models\\EquipmentItem	93	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
93	App\\Models\\EquipmentItem	94	\N	5	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
94	App\\Models\\EquipmentItem	95	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
95	App\\Models\\EquipmentItem	96	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
96	App\\Models\\EquipmentItem	97	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
97	App\\Models\\EquipmentItem	98	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
98	App\\Models\\EquipmentItem	99	\N	1	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
99	App\\Models\\EquipmentItem	100	\N	25	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
100	App\\Models\\EquipmentItem	101	\N	4	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
101	App\\Models\\EquipmentItem	102	\N	5	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
102	App\\Models\\EquipmentItem	103	\N	8	\N	2026-01-22 01:20:34	2026-01-22 01:20:34
103	App\\Models\\EquipmentItem	105	\N	24	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
104	App\\Models\\EquipmentItem	106	\N	28	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
105	App\\Models\\EquipmentItem	107	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
106	App\\Models\\EquipmentItem	108	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
107	App\\Models\\EquipmentItem	109	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
108	App\\Models\\EquipmentItem	110	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
109	App\\Models\\EquipmentItem	111	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
110	App\\Models\\EquipmentItem	112	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
111	App\\Models\\EquipmentItem	113	\N	2	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
112	App\\Models\\EquipmentItem	114	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
113	App\\Models\\EquipmentItem	115	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
114	App\\Models\\EquipmentItem	116	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
115	App\\Models\\EquipmentItem	117	\N	8	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
116	App\\Models\\EquipmentItem	118	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
117	App\\Models\\EquipmentItem	119	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
118	App\\Models\\EquipmentItem	120	\N	2	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
119	App\\Models\\EquipmentItem	121	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
120	App\\Models\\EquipmentItem	122	\N	3	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
121	App\\Models\\EquipmentItem	123	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
122	App\\Models\\EquipmentItem	124	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
123	App\\Models\\EquipmentItem	125	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
124	App\\Models\\EquipmentItem	126	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
125	App\\Models\\EquipmentItem	127	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
126	App\\Models\\EquipmentItem	128	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
127	App\\Models\\EquipmentItem	129	\N	4	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
128	App\\Models\\EquipmentItem	130	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
129	App\\Models\\EquipmentItem	131	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
130	App\\Models\\EquipmentItem	132	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
131	App\\Models\\EquipmentItem	133	\N	15	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
132	App\\Models\\EquipmentItem	134	\N	12	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
133	App\\Models\\EquipmentItem	135	\N	2	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
134	App\\Models\\EquipmentItem	136	\N	8	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
135	App\\Models\\EquipmentItem	137	\N	13	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
136	App\\Models\\EquipmentItem	138	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
137	App\\Models\\EquipmentItem	139	\N	2	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
138	App\\Models\\EquipmentItem	140	\N	6	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
139	App\\Models\\EquipmentItem	141	\N	2	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
140	App\\Models\\EquipmentItem	142	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
141	App\\Models\\EquipmentItem	143	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
142	App\\Models\\EquipmentItem	144	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
143	App\\Models\\EquipmentItem	145	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
144	App\\Models\\EquipmentItem	146	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
145	App\\Models\\EquipmentItem	147	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
146	App\\Models\\EquipmentItem	148	\N	2	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
147	App\\Models\\EquipmentItem	149	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
148	App\\Models\\EquipmentItem	150	\N	4	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
149	App\\Models\\EquipmentItem	151	\N	4	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
150	App\\Models\\EquipmentItem	152	\N	6	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
151	App\\Models\\EquipmentItem	153	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
152	App\\Models\\EquipmentItem	154	\N	3	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
153	App\\Models\\EquipmentItem	155	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
154	App\\Models\\EquipmentItem	156	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
155	App\\Models\\EquipmentItem	157	\N	2	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
156	App\\Models\\EquipmentItem	158	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
157	App\\Models\\EquipmentItem	159	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
158	App\\Models\\EquipmentItem	160	\N	5	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
159	App\\Models\\EquipmentItem	161	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
160	App\\Models\\EquipmentItem	162	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
161	App\\Models\\EquipmentItem	163	\N	5	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
162	App\\Models\\EquipmentItem	164	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
163	App\\Models\\EquipmentItem	165	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
164	App\\Models\\EquipmentItem	166	\N	2	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
165	App\\Models\\EquipmentItem	167	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
166	App\\Models\\EquipmentItem	168	\N	6	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
167	App\\Models\\EquipmentItem	169	\N	5	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
168	App\\Models\\EquipmentItem	170	\N	6	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
169	App\\Models\\EquipmentItem	171	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
170	App\\Models\\EquipmentItem	172	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
171	App\\Models\\EquipmentItem	173	\N	11	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
172	App\\Models\\EquipmentItem	174	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
173	App\\Models\\EquipmentItem	175	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
174	App\\Models\\EquipmentItem	176	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
175	App\\Models\\EquipmentItem	177	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
176	App\\Models\\EquipmentItem	178	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
177	App\\Models\\EquipmentItem	179	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
178	App\\Models\\EquipmentItem	180	\N	3	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
179	App\\Models\\EquipmentItem	181	\N	4	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
180	App\\Models\\EquipmentItem	182	\N	3	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
181	App\\Models\\EquipmentItem	183	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
182	App\\Models\\EquipmentItem	184	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
183	App\\Models\\EquipmentItem	185	\N	3	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
184	App\\Models\\EquipmentItem	186	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
185	App\\Models\\EquipmentItem	187	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
186	App\\Models\\EquipmentItem	188	\N	5	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
187	App\\Models\\EquipmentItem	189	\N	1	\N	2026-01-22 01:20:35	2026-01-22 01:20:35
\.


--
-- Data for Name: uniforms; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.uniforms (id, item_name, size, quantity_on_hand, reorder_level, unit_cost, supplier, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at) FROM stdin;
1	Admin User	admin@mbfd.gov	\N	$2y$12$22T8t3C7AmNZ8SRq5AUi0OCVRNDqF1/ltLyX3WZxsrQ/dIk9ubJs2	GzQAX91y2zgFuyljspK1mH53Tg2UYtH6F0gid2lUL1oemARGc8WfRAfZAMvU	2026-01-20 17:08:09	2026-01-20 17:08:09
2	Admin	admin@mbfd.org	\N	$2y$12$x1sQx9hXWFCTep/c/3RJM..GcqIcOaK5.H9fXG27Tt.EqFKf6sAuC	BYEtEdBD1BHS1CRyoraBRrkapwc6XnvBHpC44wicUwUE8kuvmt0V5VZbCQg7	2026-01-21 11:22:38	2026-01-21 11:22:38
\.


--
-- Name: admin_alert_events_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.admin_alert_events_id_seq', 1, false);


--
-- Name: ai_analysis_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.ai_analysis_logs_id_seq', 12, true);


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

SELECT pg_catalog.setval('public.apparatuses_id_seq', 38, true);


--
-- Name: capital_projects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.capital_projects_id_seq', 10, true);


--
-- Name: equipment_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.equipment_items_id_seq', 189, true);


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

SELECT pg_catalog.setval('public.inventory_locations_id_seq', 24, true);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.jobs_id_seq', 1, false);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.migrations_id_seq', 30, true);


--
-- Name: notification_tracking_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.notification_tracking_id_seq', 1, false);


--
-- Name: project_milestones_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.project_milestones_id_seq', 12, true);


--
-- Name: project_updates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.project_updates_id_seq', 1, false);


--
-- Name: shop_works_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.shop_works_id_seq', 1, false);


--
-- Name: stations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.stations_id_seq', 1, false);


--
-- Name: stock_mutations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.stock_mutations_id_seq', 187, true);


--
-- Name: uniforms_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.uniforms_id_seq', 1, false);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.users_id_seq', 2, true);


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
-- Name: apparatuses apparatuses_unit_id_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatuses
    ADD CONSTRAINT apparatuses_unit_id_unique UNIQUE (unit_id);


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
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: stock_mutations_stockable_type_stockable_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX stock_mutations_stockable_type_stockable_id_index ON public.stock_mutations USING btree (stockable_type, stockable_id);


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
-- Name: shop_works shop_works_apparatus_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.shop_works
    ADD CONSTRAINT shop_works_apparatus_id_foreign FOREIGN KEY (apparatus_id) REFERENCES public.apparatuses(id) ON DELETE SET NULL;


--
-- PostgreSQL database dump complete
--

\unrestrict 2w2BL3CkcFlJzb4zLvZVzXGROIZAOCdtZ5Br6s0ONkWCxsuvekH8rZORO17YxnW

