--
-- PostgreSQL database dump
--

\restrict W31CExT3Fkl0a9wK7yMa9eTfsmPh7ax7hKgjOqOyKHckWzFCy4sxHVl8tkM3as2

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
    status character varying(255) DEFAULT 'In Service'::character varying,
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
    class_description character varying(255),
    station_id bigint,
    reported_at timestamp(0) without time zone,
    CONSTRAINT apparatuses_status_check CHECK (((status)::text = ANY ((ARRAY['In Service'::character varying, 'Out of Service'::character varying, 'Maintenance'::character varying, 'Available'::character varying, 'in_service'::character varying, 'out_of_service'::character varying, 'maintenance'::character varying, 'reserve'::character varying, 'in_repair'::character varying])::text[])))
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
-- Name: big_ticket_requests; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.big_ticket_requests (
    id bigint NOT NULL,
    station_id bigint NOT NULL,
    room_type character varying(255) NOT NULL,
    room_label character varying(255),
    items json NOT NULL,
    other_item character varying(255),
    notes text,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.big_ticket_requests OWNER TO mbfd_user;

--
-- Name: big_ticket_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.big_ticket_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.big_ticket_requests_id_seq OWNER TO mbfd_user;

--
-- Name: big_ticket_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.big_ticket_requests_id_seq OWNED BY public.big_ticket_requests.id;


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
-- Name: candidate_products; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.candidate_products (
    id bigint NOT NULL,
    workgroup_session_id bigint NOT NULL,
    category_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    manufacturer character varying(255),
    model character varying(255),
    description text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.candidate_products OWNER TO mbfd_user;

--
-- Name: candidate_products_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.candidate_products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.candidate_products_id_seq OWNER TO mbfd_user;

--
-- Name: candidate_products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.candidate_products_id_seq OWNED BY public.candidate_products.id;


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
    status character varying(50) DEFAULT 'pending'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'medium'::character varying NOT NULL,
    ai_priority_rank integer,
    ai_priority_score integer,
    ai_reasoning text,
    last_ai_analysis timestamp(0) without time zone,
    percent_complete smallint,
    attachments jsonb,
    station_id bigint,
    CONSTRAINT capital_projects_priority_check CHECK (((priority)::text = ANY (ARRAY[('low'::character varying)::text, ('medium'::character varying)::text, ('high'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT capital_projects_status_check CHECK (((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('in_progress'::character varying)::text, ('on_hold'::character varying)::text, ('completed'::character varying)::text])))
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
-- Name: ch_favorites; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.ch_favorites (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id bigint NOT NULL,
    friend_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.ch_favorites OWNER TO mbfd_user;

--
-- Name: ch_messages; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.ch_messages (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    from_id bigint NOT NULL,
    to_id bigint NOT NULL,
    body text,
    attachment json,
    seen boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.ch_messages OWNER TO mbfd_user;

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
-- Name: evaluation_categories; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.evaluation_categories (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    is_rankable boolean DEFAULT true NOT NULL,
    display_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    assessment_profile character varying(255) DEFAULT 'generic_apparatus'::character varying,
    instructions_markdown text,
    score_visibility_notes text,
    finalists_limit integer,
    CONSTRAINT evaluation_categories_assessment_profile_check CHECK (((assessment_profile)::text = ANY ((ARRAY['generic_apparatus'::character varying, 'powered_tool'::character varying, 'hand_tool_forcible'::character varying, 'stabilization_support'::character varying, 'water_flow_appliance'::character varying])::text[])))
);


ALTER TABLE public.evaluation_categories OWNER TO mbfd_user;

--
-- Name: evaluation_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.evaluation_categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.evaluation_categories_id_seq OWNER TO mbfd_user;

--
-- Name: evaluation_categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.evaluation_categories_id_seq OWNED BY public.evaluation_categories.id;


--
-- Name: evaluation_comments; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.evaluation_comments (
    id bigint NOT NULL,
    submission_id bigint NOT NULL,
    category_id bigint,
    comment text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.evaluation_comments OWNER TO mbfd_user;

--
-- Name: evaluation_comments_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.evaluation_comments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.evaluation_comments_id_seq OWNER TO mbfd_user;

--
-- Name: evaluation_comments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.evaluation_comments_id_seq OWNED BY public.evaluation_comments.id;


--
-- Name: evaluation_criteria; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.evaluation_criteria (
    id bigint NOT NULL,
    template_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    max_score integer DEFAULT 10 NOT NULL,
    weight numeric(5,2) DEFAULT '1'::numeric NOT NULL,
    display_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.evaluation_criteria OWNER TO mbfd_user;

--
-- Name: evaluation_criteria_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.evaluation_criteria_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.evaluation_criteria_id_seq OWNER TO mbfd_user;

--
-- Name: evaluation_criteria_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.evaluation_criteria_id_seq OWNED BY public.evaluation_criteria.id;


--
-- Name: evaluation_scores; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.evaluation_scores (
    id bigint NOT NULL,
    submission_id bigint NOT NULL,
    criterion_id bigint NOT NULL,
    score numeric(5,2),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.evaluation_scores OWNER TO mbfd_user;

--
-- Name: evaluation_scores_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.evaluation_scores_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.evaluation_scores_id_seq OWNER TO mbfd_user;

--
-- Name: evaluation_scores_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.evaluation_scores_id_seq OWNED BY public.evaluation_scores.id;


--
-- Name: evaluation_submissions; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.evaluation_submissions (
    id bigint NOT NULL,
    workgroup_member_id bigint NOT NULL,
    candidate_product_id bigint NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    submitted_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    rubric_version character varying(50),
    assessment_profile character varying(50),
    overall_score numeric(5,2),
    capability_score numeric(5,2),
    usability_score numeric(5,2),
    affordability_score numeric(5,2),
    maintainability_score numeric(5,2),
    deployability_score numeric(5,2),
    advance_recommendation character varying(255),
    confidence_level character varying(255),
    has_deal_breaker boolean DEFAULT false NOT NULL,
    deal_breaker_note text,
    criterion_payload jsonb,
    narrative_payload jsonb,
    is_locked boolean DEFAULT false NOT NULL,
    session_id bigint,
    user_id bigint,
    CONSTRAINT evaluation_submissions_advance_recommendation_check CHECK (((advance_recommendation)::text = ANY ((ARRAY['yes'::character varying, 'maybe'::character varying, 'no'::character varying])::text[]))),
    CONSTRAINT evaluation_submissions_confidence_level_check CHECK (((confidence_level)::text = ANY ((ARRAY['low'::character varying, 'medium'::character varying, 'high'::character varying])::text[]))),
    CONSTRAINT evaluation_submissions_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'submitted'::character varying])::text[])))
);


ALTER TABLE public.evaluation_submissions OWNER TO mbfd_user;

--
-- Name: evaluation_submissions_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.evaluation_submissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.evaluation_submissions_id_seq OWNER TO mbfd_user;

--
-- Name: evaluation_submissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.evaluation_submissions_id_seq OWNED BY public.evaluation_submissions.id;


--
-- Name: evaluation_templates; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.evaluation_templates (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    category_id bigint NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.evaluation_templates OWNER TO mbfd_user;

--
-- Name: evaluation_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.evaluation_templates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.evaluation_templates_id_seq OWNER TO mbfd_user;

--
-- Name: evaluation_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.evaluation_templates_id_seq OWNED BY public.evaluation_templates.id;


--
-- Name: external_nav_items; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.external_nav_items (
    id bigint NOT NULL,
    division character varying(255) DEFAULT 'training'::character varying NOT NULL,
    label character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    type character varying(255) DEFAULT 'iframe'::character varying NOT NULL,
    url text,
    external_source_id bigint,
    baserow_workspace_id integer,
    baserow_database_id integer,
    baserow_table_id integer,
    baserow_view_id integer,
    allowed_roles json DEFAULT '[]'::json NOT NULL,
    allowed_permissions json,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    open_in_new_tab boolean DEFAULT false NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.external_nav_items OWNER TO mbfd_user;

--
-- Name: external_nav_items_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.external_nav_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.external_nav_items_id_seq OWNER TO mbfd_user;

--
-- Name: external_nav_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.external_nav_items_id_seq OWNED BY public.external_nav_items.id;


--
-- Name: external_sources; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.external_sources (
    id bigint NOT NULL,
    division character varying(255) DEFAULT 'training'::character varying NOT NULL,
    name character varying(255) NOT NULL,
    provider character varying(255) DEFAULT 'baserow'::character varying NOT NULL,
    base_url character varying(255) NOT NULL,
    token_encrypted text,
    token_hint character varying(255),
    status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.external_sources OWNER TO mbfd_user;

--
-- Name: external_sources_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.external_sources_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.external_sources_id_seq OWNER TO mbfd_user;

--
-- Name: external_sources_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.external_sources_id_seq OWNED BY public.external_sources.id;


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
-- Name: inventory_categories; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.inventory_categories (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.inventory_categories OWNER TO mbfd_user;

--
-- Name: inventory_categories_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.inventory_categories_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inventory_categories_id_seq OWNER TO mbfd_user;

--
-- Name: inventory_categories_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.inventory_categories_id_seq OWNED BY public.inventory_categories.id;


--
-- Name: inventory_items; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.inventory_items (
    id bigint NOT NULL,
    category_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    sku character varying(255),
    par_quantity integer DEFAULT 1 NOT NULL,
    active boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    unit_label character varying(255) DEFAULT 'units'::character varying NOT NULL,
    unit_multiplier integer DEFAULT 1 NOT NULL,
    low_threshold integer
);


ALTER TABLE public.inventory_items OWNER TO mbfd_user;

--
-- Name: inventory_items_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.inventory_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inventory_items_id_seq OWNER TO mbfd_user;

--
-- Name: inventory_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.inventory_items_id_seq OWNED BY public.inventory_items.id;


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
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
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
-- Name: room_assets; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.room_assets (
    id bigint NOT NULL,
    room_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    quantity integer DEFAULT 1 NOT NULL,
    category character varying(255),
    condition character varying(255),
    serial_number character varying(255),
    purchase_date date,
    purchase_price numeric(10,2),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.room_assets OWNER TO mbfd_user;

--
-- Name: room_assets_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.room_assets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.room_assets_id_seq OWNER TO mbfd_user;

--
-- Name: room_assets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.room_assets_id_seq OWNED BY public.room_assets.id;


--
-- Name: room_audit_items; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.room_audit_items (
    id bigint NOT NULL,
    room_audit_id bigint NOT NULL,
    room_asset_id bigint,
    status character varying(255) DEFAULT 'Verified'::character varying NOT NULL,
    notes text,
    photos json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT room_audit_items_status_check CHECK (((status)::text = ANY (ARRAY[('Verified'::character varying)::text, ('Missing'::character varying)::text, ('Damaged'::character varying)::text, ('Extra'::character varying)::text])))
);


ALTER TABLE public.room_audit_items OWNER TO mbfd_user;

--
-- Name: room_audit_items_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.room_audit_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.room_audit_items_id_seq OWNER TO mbfd_user;

--
-- Name: room_audit_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.room_audit_items_id_seq OWNED BY public.room_audit_items.id;


--
-- Name: room_audits; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.room_audits (
    id bigint NOT NULL,
    room_id bigint NOT NULL,
    user_id bigint,
    audit_date timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    status character varying(255) DEFAULT 'In Progress'::character varying NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT room_audits_status_check CHECK (((status)::text = ANY (ARRAY[('In Progress'::character varying)::text, ('Completed'::character varying)::text, ('Verified'::character varying)::text])))
);


ALTER TABLE public.room_audits OWNER TO mbfd_user;

--
-- Name: room_audits_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.room_audits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.room_audits_id_seq OWNER TO mbfd_user;

--
-- Name: room_audits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.room_audits_id_seq OWNED BY public.room_audits.id;


--
-- Name: rooms; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.rooms (
    id bigint NOT NULL,
    station_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    type character varying(255),
    capacity integer,
    floor character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.rooms OWNER TO mbfd_user;

--
-- Name: rooms_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.rooms_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.rooms_id_seq OWNER TO mbfd_user;

--
-- Name: rooms_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.rooms_id_seq OWNED BY public.rooms.id;


--
-- Name: session_user; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public."session_user" (
    id bigint NOT NULL,
    workgroup_session_id bigint NOT NULL,
    user_id bigint NOT NULL,
    is_official_evaluator boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public."session_user" OWNER TO mbfd_user;

--
-- Name: session_user_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.session_user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.session_user_id_seq OWNER TO mbfd_user;

--
-- Name: session_user_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.session_user_id_seq OWNED BY public."session_user".id;


--
-- Name: session_workgroup_member_attendance; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.session_workgroup_member_attendance (
    id bigint NOT NULL,
    workgroup_session_id bigint CONSTRAINT session_workgroup_member_attendan_workgroup_session_id_not_null NOT NULL,
    workgroup_member_id bigint CONSTRAINT session_workgroup_member_attendanc_workgroup_member_id_not_null NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.session_workgroup_member_attendance OWNER TO mbfd_user;

--
-- Name: session_workgroup_member_attendance_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.session_workgroup_member_attendance_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.session_workgroup_member_attendance_id_seq OWNER TO mbfd_user;

--
-- Name: session_workgroup_member_attendance_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.session_workgroup_member_attendance_id_seq OWNED BY public.session_workgroup_member_attendance.id;


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
    CONSTRAINT shop_works_status_check CHECK (((status)::text = ANY (ARRAY[('Pending'::character varying)::text, ('In Progress'::character varying)::text, ('Waiting for Parts'::character varying)::text, ('Completed'::character varying)::text, ('Cancelled'::character varying)::text])))
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
-- Name: single_gas_meters; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.single_gas_meters (
    id bigint NOT NULL,
    apparatus_id bigint NOT NULL,
    serial_number character varying(5) NOT NULL,
    activation_date date NOT NULL,
    expiration_date date NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.single_gas_meters OWNER TO mbfd_user;

--
-- Name: single_gas_meters_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.single_gas_meters_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.single_gas_meters_id_seq OWNER TO mbfd_user;

--
-- Name: single_gas_meters_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.single_gas_meters_id_seq OWNED BY public.single_gas_meters.id;


--
-- Name: station_inventory_audits; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.station_inventory_audits (
    id bigint NOT NULL,
    station_id bigint NOT NULL,
    inventory_item_id bigint,
    actor_name character varying(255) NOT NULL,
    actor_shift character varying(255) NOT NULL,
    action character varying(255) NOT NULL,
    from_value json,
    to_value json,
    created_at timestamp(0) without time zone NOT NULL
);


ALTER TABLE public.station_inventory_audits OWNER TO mbfd_user;

--
-- Name: station_inventory_audits_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.station_inventory_audits_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.station_inventory_audits_id_seq OWNER TO mbfd_user;

--
-- Name: station_inventory_audits_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.station_inventory_audits_id_seq OWNED BY public.station_inventory_audits.id;


--
-- Name: station_inventory_items; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.station_inventory_items (
    id bigint NOT NULL,
    station_id bigint NOT NULL,
    inventory_item_id bigint NOT NULL,
    on_hand integer DEFAULT 0 NOT NULL,
    status character varying(255) DEFAULT 'ok'::character varying NOT NULL,
    last_updated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.station_inventory_items OWNER TO mbfd_user;

--
-- Name: station_inventory_items_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.station_inventory_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.station_inventory_items_id_seq OWNER TO mbfd_user;

--
-- Name: station_inventory_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.station_inventory_items_id_seq OWNED BY public.station_inventory_items.id;


--
-- Name: station_inventory_submissions; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.station_inventory_submissions (
    id bigint NOT NULL,
    station_id bigint NOT NULL,
    items json NOT NULL,
    pdf_path character varying(255),
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    employee_name character varying(255),
    shift character varying(255),
    notes text,
    submitted_at timestamp(0) without time zone
);


ALTER TABLE public.station_inventory_submissions OWNER TO mbfd_user;

--
-- Name: station_inventory_submissions_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.station_inventory_submissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.station_inventory_submissions_id_seq OWNER TO mbfd_user;

--
-- Name: station_inventory_submissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.station_inventory_submissions_id_seq OWNED BY public.station_inventory_submissions.id;


--
-- Name: station_supply_requests; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.station_supply_requests (
    id bigint NOT NULL,
    station_id bigint NOT NULL,
    request_text text NOT NULL,
    status character varying(255) DEFAULT 'open'::character varying NOT NULL,
    created_by_name character varying(255) NOT NULL,
    created_by_shift character varying(255) NOT NULL,
    admin_notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.station_supply_requests OWNER TO mbfd_user;

--
-- Name: station_supply_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.station_supply_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.station_supply_requests_id_seq OWNER TO mbfd_user;

--
-- Name: station_supply_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.station_supply_requests_id_seq OWNED BY public.station_supply_requests.id;


--
-- Name: stations; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.stations (
    id bigint NOT NULL,
    station_number character varying(255) NOT NULL,
    address character varying(255) NOT NULL,
    city character varying(255) DEFAULT 'Miami Beach'::character varying NOT NULL,
    state character varying(255) DEFAULT 'FL'::character varying NOT NULL,
    zip_code character varying(255),
    captain_in_charge character varying(255),
    phone character varying(255),
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    inventory_pin_hash character varying(255),
    name character varying(255),
    is_active boolean DEFAULT true NOT NULL
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
-- Name: training_todo_updates; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.training_todo_updates (
    id bigint NOT NULL,
    training_todo_id bigint NOT NULL,
    user_id bigint,
    username character varying(255),
    comment text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.training_todo_updates OWNER TO mbfd_user;

--
-- Name: training_todo_updates_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.training_todo_updates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.training_todo_updates_id_seq OWNER TO mbfd_user;

--
-- Name: training_todo_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.training_todo_updates_id_seq OWNED BY public.training_todo_updates.id;


--
-- Name: training_todos; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.training_todos (
    id bigint NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    is_completed boolean DEFAULT false NOT NULL,
    sort integer DEFAULT 0 NOT NULL,
    assigned_to json,
    created_by bigint,
    completed_at timestamp(0) without time zone,
    attachments json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    priority character varying(255) DEFAULT 'medium'::character varying NOT NULL
);


ALTER TABLE public.training_todos OWNER TO mbfd_user;

--
-- Name: training_todos_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.training_todos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.training_todos_id_seq OWNER TO mbfd_user;

--
-- Name: training_todos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.training_todos_id_seq OWNED BY public.training_todos.id;


--
-- Name: under_25k_project_updates; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.under_25k_project_updates (
    id bigint NOT NULL,
    under_25k_project_id bigint,
    user_id bigint,
    title character varying(255),
    body text,
    percent_complete_snapshot integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.under_25k_project_updates OWNER TO mbfd_user;

--
-- Name: under_25k_project_updates_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.under_25k_project_updates_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.under_25k_project_updates_id_seq OWNER TO mbfd_user;

--
-- Name: under_25k_project_updates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.under_25k_project_updates_id_seq OWNED BY public.under_25k_project_updates.id;


--
-- Name: under_25k_projects; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.under_25k_projects (
    id bigint NOT NULL,
    project_number character varying(255),
    name character varying(255),
    description text,
    budget_amount numeric(14,2),
    spend_amount numeric(14,2),
    status character varying(255),
    priority character varying(255),
    start_date date,
    target_completion_date date,
    actual_completion_date date,
    project_manager character varying(255),
    notes text,
    percent_complete integer,
    internal_notes text,
    attachments json,
    attachment_file_names json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    zone character varying(255),
    miami_beach_area character varying(255),
    munis_adopted_amended numeric(12,2),
    munis_transfers_in_out numeric(12,2),
    munis_revised_budget numeric(12,2),
    internal_transfers_in_out numeric(12,2),
    internal_revised_budget numeric(12,2),
    requisitions numeric(12,2),
    actual_expenses numeric(12,2),
    project_balance_savings numeric(12,2),
    last_comment_date date,
    latest_comment text,
    vfa_update text,
    vfa_update_date date,
    station_id bigint
);


ALTER TABLE public.under_25k_projects OWNER TO mbfd_user;

--
-- Name: under_25k_projects_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.under_25k_projects_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.under_25k_projects_id_seq OWNER TO mbfd_user;

--
-- Name: under_25k_projects_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.under_25k_projects_id_seq OWNED BY public.under_25k_projects.id;


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
    CONSTRAINT uniforms_size_check CHECK (((size)::text = ANY (ARRAY[('XS'::character varying)::text, ('S'::character varying)::text, ('M'::character varying)::text, ('L'::character varying)::text, ('XL'::character varying)::text, ('XXL'::character varying)::text, ('XXXL'::character varying)::text])))
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
-- Name: unit_master_vehicles; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.unit_master_vehicles (
    id bigint NOT NULL,
    veh_number character varying(255),
    make character varying(255),
    model character varying(255),
    year character varying(255),
    tag_number character varying(255),
    dept_code character varying(255),
    employee_or_vehicle_name character varying(255),
    sunpass_number character varying(255),
    als_license character varying(255),
    serial_number character varying(255),
    section character varying(255),
    assignment character varying(255),
    location character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.unit_master_vehicles OWNER TO mbfd_user;

--
-- Name: unit_master_vehicles_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.unit_master_vehicles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.unit_master_vehicles_id_seq OWNER TO mbfd_user;

--
-- Name: unit_master_vehicles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.unit_master_vehicles_id_seq OWNED BY public.unit_master_vehicles.id;


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
    must_change_password boolean DEFAULT false NOT NULL,
    is_admin boolean DEFAULT false NOT NULL,
    panel character varying(255) DEFAULT 'admin'::character varying,
    plain_password character varying(255)
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
-- Name: workgroup_files; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.workgroup_files (
    id bigint NOT NULL,
    workgroup_id bigint NOT NULL,
    workgroup_session_id bigint,
    filename character varying(255) NOT NULL,
    filepath character varying(255) NOT NULL,
    file_type character varying(255),
    file_size integer,
    uploaded_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.workgroup_files OWNER TO mbfd_user;

--
-- Name: workgroup_files_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.workgroup_files_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.workgroup_files_id_seq OWNER TO mbfd_user;

--
-- Name: workgroup_files_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.workgroup_files_id_seq OWNED BY public.workgroup_files.id;


--
-- Name: workgroup_members; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.workgroup_members (
    id bigint NOT NULL,
    workgroup_id bigint NOT NULL,
    user_id bigint NOT NULL,
    role character varying(255) DEFAULT 'member'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    count_evaluations boolean DEFAULT true NOT NULL,
    CONSTRAINT workgroup_members_role_check CHECK (((role)::text = ANY ((ARRAY['admin'::character varying, 'facilitator'::character varying, 'member'::character varying])::text[])))
);


ALTER TABLE public.workgroup_members OWNER TO mbfd_user;

--
-- Name: workgroup_members_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.workgroup_members_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.workgroup_members_id_seq OWNER TO mbfd_user;

--
-- Name: workgroup_members_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.workgroup_members_id_seq OWNED BY public.workgroup_members.id;


--
-- Name: workgroup_notes; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.workgroup_notes (
    id bigint NOT NULL,
    workgroup_member_id bigint NOT NULL,
    workgroup_session_id bigint,
    title character varying(255) NOT NULL,
    content text NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_shared boolean DEFAULT false NOT NULL,
    shared_with_user_id bigint
);


ALTER TABLE public.workgroup_notes OWNER TO mbfd_user;

--
-- Name: workgroup_notes_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.workgroup_notes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.workgroup_notes_id_seq OWNER TO mbfd_user;

--
-- Name: workgroup_notes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.workgroup_notes_id_seq OWNED BY public.workgroup_notes.id;


--
-- Name: workgroup_sessions; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.workgroup_sessions (
    id bigint NOT NULL,
    workgroup_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT workgroup_sessions_status_check CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'active'::character varying, 'completed'::character varying])::text[])))
);


ALTER TABLE public.workgroup_sessions OWNER TO mbfd_user;

--
-- Name: workgroup_sessions_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.workgroup_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.workgroup_sessions_id_seq OWNER TO mbfd_user;

--
-- Name: workgroup_sessions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.workgroup_sessions_id_seq OWNED BY public.workgroup_sessions.id;


--
-- Name: workgroup_shared_uploads; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.workgroup_shared_uploads (
    id bigint NOT NULL,
    workgroup_id bigint NOT NULL,
    workgroup_session_id bigint NOT NULL,
    user_id bigint NOT NULL,
    workgroup_member_id bigint NOT NULL,
    filename character varying(255) NOT NULL,
    filepath character varying(255) NOT NULL,
    file_type character varying(255),
    file_size integer,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.workgroup_shared_uploads OWNER TO mbfd_user;

--
-- Name: workgroup_shared_uploads_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.workgroup_shared_uploads_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.workgroup_shared_uploads_id_seq OWNER TO mbfd_user;

--
-- Name: workgroup_shared_uploads_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.workgroup_shared_uploads_id_seq OWNED BY public.workgroup_shared_uploads.id;


--
-- Name: workgroups; Type: TABLE; Schema: public; Owner: mbfd_user
--

CREATE TABLE public.workgroups (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.workgroups OWNER TO mbfd_user;

--
-- Name: workgroups_id_seq; Type: SEQUENCE; Schema: public; Owner: mbfd_user
--

CREATE SEQUENCE public.workgroups_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.workgroups_id_seq OWNER TO mbfd_user;

--
-- Name: workgroups_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: mbfd_user
--

ALTER SEQUENCE public.workgroups_id_seq OWNED BY public.workgroups.id;


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
-- Name: big_ticket_requests id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.big_ticket_requests ALTER COLUMN id SET DEFAULT nextval('public.big_ticket_requests_id_seq'::regclass);


--
-- Name: candidate_products id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.candidate_products ALTER COLUMN id SET DEFAULT nextval('public.candidate_products_id_seq'::regclass);


--
-- Name: capital_projects id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.capital_projects ALTER COLUMN id SET DEFAULT nextval('public.capital_projects_id_seq'::regclass);


--
-- Name: equipment_items id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.equipment_items ALTER COLUMN id SET DEFAULT nextval('public.equipment_items_id_seq'::regclass);


--
-- Name: evaluation_categories id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_categories ALTER COLUMN id SET DEFAULT nextval('public.evaluation_categories_id_seq'::regclass);


--
-- Name: evaluation_comments id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_comments ALTER COLUMN id SET DEFAULT nextval('public.evaluation_comments_id_seq'::regclass);


--
-- Name: evaluation_criteria id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_criteria ALTER COLUMN id SET DEFAULT nextval('public.evaluation_criteria_id_seq'::regclass);


--
-- Name: evaluation_scores id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_scores ALTER COLUMN id SET DEFAULT nextval('public.evaluation_scores_id_seq'::regclass);


--
-- Name: evaluation_submissions id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_submissions ALTER COLUMN id SET DEFAULT nextval('public.evaluation_submissions_id_seq'::regclass);


--
-- Name: evaluation_templates id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_templates ALTER COLUMN id SET DEFAULT nextval('public.evaluation_templates_id_seq'::regclass);


--
-- Name: external_nav_items id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.external_nav_items ALTER COLUMN id SET DEFAULT nextval('public.external_nav_items_id_seq'::regclass);


--
-- Name: external_sources id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.external_sources ALTER COLUMN id SET DEFAULT nextval('public.external_sources_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: import_runs id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.import_runs ALTER COLUMN id SET DEFAULT nextval('public.import_runs_id_seq'::regclass);


--
-- Name: inventory_categories id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.inventory_categories ALTER COLUMN id SET DEFAULT nextval('public.inventory_categories_id_seq'::regclass);


--
-- Name: inventory_items id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.inventory_items ALTER COLUMN id SET DEFAULT nextval('public.inventory_items_id_seq'::regclass);


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
-- Name: room_assets id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_assets ALTER COLUMN id SET DEFAULT nextval('public.room_assets_id_seq'::regclass);


--
-- Name: room_audit_items id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_audit_items ALTER COLUMN id SET DEFAULT nextval('public.room_audit_items_id_seq'::regclass);


--
-- Name: room_audits id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_audits ALTER COLUMN id SET DEFAULT nextval('public.room_audits_id_seq'::regclass);


--
-- Name: rooms id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.rooms ALTER COLUMN id SET DEFAULT nextval('public.rooms_id_seq'::regclass);


--
-- Name: session_user id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public."session_user" ALTER COLUMN id SET DEFAULT nextval('public.session_user_id_seq'::regclass);


--
-- Name: session_workgroup_member_attendance id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.session_workgroup_member_attendance ALTER COLUMN id SET DEFAULT nextval('public.session_workgroup_member_attendance_id_seq'::regclass);


--
-- Name: shop_works id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.shop_works ALTER COLUMN id SET DEFAULT nextval('public.shop_works_id_seq'::regclass);


--
-- Name: single_gas_meters id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.single_gas_meters ALTER COLUMN id SET DEFAULT nextval('public.single_gas_meters_id_seq'::regclass);


--
-- Name: station_inventory_audits id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_audits ALTER COLUMN id SET DEFAULT nextval('public.station_inventory_audits_id_seq'::regclass);


--
-- Name: station_inventory_items id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_items ALTER COLUMN id SET DEFAULT nextval('public.station_inventory_items_id_seq'::regclass);


--
-- Name: station_inventory_submissions id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_submissions ALTER COLUMN id SET DEFAULT nextval('public.station_inventory_submissions_id_seq'::regclass);


--
-- Name: station_supply_requests id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_supply_requests ALTER COLUMN id SET DEFAULT nextval('public.station_supply_requests_id_seq'::regclass);


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
-- Name: training_todo_updates id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.training_todo_updates ALTER COLUMN id SET DEFAULT nextval('public.training_todo_updates_id_seq'::regclass);


--
-- Name: training_todos id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.training_todos ALTER COLUMN id SET DEFAULT nextval('public.training_todos_id_seq'::regclass);


--
-- Name: under_25k_project_updates id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.under_25k_project_updates ALTER COLUMN id SET DEFAULT nextval('public.under_25k_project_updates_id_seq'::regclass);


--
-- Name: under_25k_projects id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.under_25k_projects ALTER COLUMN id SET DEFAULT nextval('public.under_25k_projects_id_seq'::regclass);


--
-- Name: uniforms id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.uniforms ALTER COLUMN id SET DEFAULT nextval('public.uniforms_id_seq'::regclass);


--
-- Name: unit_master_vehicles id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.unit_master_vehicles ALTER COLUMN id SET DEFAULT nextval('public.unit_master_vehicles_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: workgroup_files id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_files ALTER COLUMN id SET DEFAULT nextval('public.workgroup_files_id_seq'::regclass);


--
-- Name: workgroup_members id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_members ALTER COLUMN id SET DEFAULT nextval('public.workgroup_members_id_seq'::regclass);


--
-- Name: workgroup_notes id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_notes ALTER COLUMN id SET DEFAULT nextval('public.workgroup_notes_id_seq'::regclass);


--
-- Name: workgroup_sessions id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_sessions ALTER COLUMN id SET DEFAULT nextval('public.workgroup_sessions_id_seq'::regclass);


--
-- Name: workgroup_shared_uploads id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_shared_uploads ALTER COLUMN id SET DEFAULT nextval('public.workgroup_shared_uploads_id_seq'::regclass);


--
-- Name: workgroups id; Type: DEFAULT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroups ALTER COLUMN id SET DEFAULT nextval('public.workgroups_id_seq'::regclass);


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

COPY public.apparatuses (id, unit_id, vin, make, model, year, status, mileage, last_service_date, notes, created_at, updated_at, name, type, vehicle_number, slug, designation, assignment, current_location, class_description, station_id, reported_at) FROM stdin;
50	E4	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E4	Engine	20504	e4	E 4	Station 4	Station 4	ENGINE	32	2026-01-26 10:00:06
51	R4	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-03-03 21:32:36	RESCUE R4	Rescue	17502	r4	R 4	Station 4	Station 4	RESCUE	32	2026-03-03 21:32:36
52	R44	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R44	Rescue	17503	r44	R 44	Station 4	Station 4	RESCUE	32	2026-01-26 10:00:06
56	L11	\N	\N	\N	\N	In Service	0	\N	In service as L1	2026-01-26 10:00:06	2026-03-09 11:10:51	LADDER L11	Ladder	002-6	l11	L 11	Reserve	Station 1	LADDER	29	2026-03-03 21:32:36
57	R-1033	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-03-09 11:10:51	RESCUE 1033	Rescue	1033	r1033	Reserve	Reserve	Station 2	RESCUE	30	2026-03-03 21:32:36
58	R-1034	\N	\N	\N	\N	In Service	0	\N	Detail Sunday	2026-01-26 10:00:06	2026-03-09 11:10:51	RESCUE 1034	Rescue	1034	r1034	Reserve	Reserve	Station 2	RESCUE	30	2026-03-03 21:32:36
40	R1	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R1	Rescue	16508	r1	R 1	Station 1	Station 1	RESCUE	29	2026-01-26 10:00:06
41	R11	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R11	Rescue	19502	r11	R 11	Station 1	Station 1	RESCUE	29	2026-01-26 10:00:06
42	A1	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	AIR TRUCK A1	Air Truck	002-20	a1	A 1	Station 2	Station 2	AIR TRUCK	30	2026-01-26 10:00:06
43	A2	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	AIR TRUCK A2	Air Truck	18500	a2	A 2	Station 2	Station 2	AIR TRUCK	30	2026-01-26 10:00:06
44	E2	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E2	Engine	24509	e2	E 2	Station 2	Station 2	ENGINE	30	2026-01-26 10:00:06
63	\N	1GNSKEEGXPR511447	Chevrolet	Tahoe	2024	In Service	15123	\N	\N	2026-02-03 16:17:08	2026-03-09 11:47:04	\N	Command Vehicle	22530	\N	Captain 5	Station 2	Station 2	\N	30	2026-03-09 11:47:04
55	E31	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E31	Engine	002-10	e31	E 31	Reserve	Station 2	ENGINE	30	2026-01-26 10:00:06
46	R22	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R22	Rescue	19503	r22	R 22	Station 2	Station 2	RESCUE	30	2026-01-26 10:00:06
47	E3	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	ENGINE E3	Engine	002-22	e3	E 3	Station 3	Station 3	ENGINE	31	2026-01-26 10:00:06
48	L3	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	LADDER L3	Ladder	17505	l3	L 3	Station 3	Station 3	LADDER	31	2026-01-26 10:00:06
49	R3	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-01-26 10:00:06	RESCUE R3	Rescue	17501	r3	R 3	Station 3	Station 3	RESCUE	31	2026-01-26 10:00:06
54	E21	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-03-03 21:32:36	ENGINE E21	Engine	002-16	e21	E 21	Reserve	Station 2	ENGINE	30	2026-03-03 21:32:36
53	E11	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-03-03 21:32:36	ENGINE E11	Engine	002-14	e11	E 11	Reserve	Station 2	ENGINE	30	2026-03-03 21:32:36
39	L1	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-03-03 21:32:36	LADDER L1	Ladder	002-12	l1	L 1	Station 1	Station 1	LADDER	29	2026-03-03 21:32:36
45	R2	\N	\N	\N	\N	Out of Service	0	\N	Repairs to sub floor	2026-01-26 10:00:06	2026-03-03 21:32:36	RESCUE R2	Rescue	16507	r2	R 2	Station 2	Station 2	RESCUE	30	2026-03-03 21:32:36
38	E1	\N	\N	\N	\N	In Service	0	\N	\N	2026-01-26 10:00:06	2026-03-03 21:32:36	ENGINE E1	Engine	20503	e1	E 1	Station 1	Station 1	ENGINE	29	2026-03-03 21:32:36
59	R-1035	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-03-09 11:10:51	RESCUE 1035	Rescue	1035	r1035	Reserve	Reserve	Station 1	RESCUE	29	2026-01-26 10:00:06
60	R-1036	\N	\N	\N	\N	In Service	0	\N	Detail Sunday	2026-01-26 10:00:06	2026-03-09 11:10:51	RESCUE 1036	Rescue	1036	r1036	Reserve	Reserve	Station 2	RESCUE	30	2026-03-03 21:32:36
61	R-14500	\N	\N	\N	\N	Available	0	\N	\N	2026-01-26 10:00:06	2026-03-09 11:10:51	RESCUE 14500	Rescue	14500	r14500	Reserve	Reserve	Station 2	RESCUE	30	2026-01-26 10:00:06
62	R-14501	\N	\N	\N	\N	In Service	0	\N	In Service as R2	2026-01-26 10:00:06	2026-03-09 11:10:51	RESCUE 14501	Rescue	14501	r14501	Reserve	Reserve	Station 2	RESCUE	30	2026-03-03 21:32:36
\.


--
-- Data for Name: big_ticket_requests; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.big_ticket_requests (id, station_id, room_type, room_label, items, other_item, notes, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.cache (key, value, expiration) FROM stdin;
workgroup_ai_exec_report_1	a:5:{s:6:"report";s:6366:"**EXECUTIVE DATA ANALYSIS REPORT**\n**Mid-Mount Ladder Truck Equipment Evaluation — Data Analysis**\n**Session: Day 2**\n**Date: March 9, 2026**\n**Prepared for: Health & Safety Committee, Fire Chief Digna Abello**\n\n## 1. EXECUTIVE OVERVIEW\n\nThe purpose of the Mid-Mount Ladder Truck Equipment Evaluation is to assess and compare various equipment options for the Miami Beach Fire Department. This effort aims to collect comprehensive data on the performance, usability, and suitability of different products for the department's needs. Over the course of the evaluation, a total of 5 products were assessed across 3 categories by 2 evaluators, resulting in 10 submissions.\n\nThe evaluation categories included Forcible Entry Tools, Battery-Operated Extrication Tools, and Vehicle Stabilization. Each category was designed to test specific aspects of the equipment, providing a detailed understanding of their capabilities and limitations. The participation of 2 evaluators ensured a dual perspective on each product, enhancing the reliability and validity of the collected data.\n\n## 2. DATA ANALYSIS BY CATEGORY\n\n### 2.1 Forcible Entry Tools\n\nIn the Forcible Entry Tools category, only one product was evaluated: the 12-inch Cut-Off Saw by Dewalt. This product received a score of 90.56/100, indicating a high level of satisfaction among the evaluators. The score suggests that the Dewalt 12-inch Cut-Off Saw meets the department's requirements for forcible entry tools, demonstrating strong performance and usability.\n\nReference to the uploaded vendor specification documents reveals that the Dewalt 12-inch Cut-Off Saw boasts a powerful motor and durable construction, which likely contributed to its high score. The consensus among evaluators on this product's excellence is notable, with both evaluators ranking it as a finalist.\n\n### 2.2 Battery-Operated Extrication Tools\n\nThe Battery-Operated Extrication Tools category saw the evaluation of three products from TNT: a battery-powered ram, a battery-powered spreader, and a battery-powered cutter. All three products received high scores, with the ram scoring 87.72/100, the spreader scoring 86.78/100, and the cutter scoring 84.44/100. These scores indicate a strong performance across the board for TNT's products in this category.\n\nAnalyzing the scores by brand grouping, it's clear that TNT's products are highly regarded by the evaluators. The minor variations in scores among the three products suggest that each has its strengths and weaknesses, but all are considered finalists. The vendor specification documents highlight TNT's focus on battery technology and tool design, which seems to pay off in terms of performance and usability.\n\n### 2.3 Vehicle Stabilization\n\nIn the Vehicle Stabilization category, the Vehicle Stabilization Strut by Holmatro was evaluated, receiving a score of 90.27/100. This high score indicates that the product is highly effective and meets the department's needs for vehicle stabilization. The evaluators' consensus on this product's quality is evident, with both ranking it as a finalist.\n\nThe vendor specification documents for the Holmatro Vehicle Stabilization Strut reveal a product designed with safety and efficiency in mind, featuring a robust construction and easy deployment mechanism. This design likely contributed to the product's high score and its designation as a finalist.\n\n## 3. EVALUATOR FEEDBACK ANALYSIS\n\nCommon themes across evaluator narratives include the importance of durability, ease of use, and performance under various conditions. There was strong agreement on the quality and effectiveness of the products that received high scores, such as the Dewalt 12-inch Cut-Off Saw and the Holmatro Vehicle Stabilization Strut.\n\nAreas of divergent opinions were minimal but included discussions on the versatility of certain tools and their potential for multi-purpose use. Notable deal-breaker reports were scarce, indicating that the evaluated products generally met the department's expectations. However, some evaluators noted the importance of considering maintenance costs and the availability of spare parts, which could influence future procurement decisions.\n\n## 4. DATA PATTERNS & OBSERVATIONS\n\nThe score distributions across categories show a tendency towards high scores, indicating that the evaluated products are of high quality and meet the department's needs. Statistical observations reveal a tight clustering of scores within each category, suggesting that the products are closely matched in terms of performance and usability.\n\nThe categories with clear frontrunners, such as the Dewalt 12-inch Cut-Off Saw in Forcible Entry Tools and the Holmatro Vehicle Stabilization Strut in Vehicle Stabilization, demonstrate a high level of consensus among evaluators. In contrast, the Battery-Operated Extrication Tools category shows a closer competition among the products, with all three TNT tools receiving high scores.\n\nData anomalies or outliers were not significantly present, with all products receiving scores that reflect their performance and usability. This consistency suggests that the evaluation process was robust and that the data collected provides a reliable basis for understanding the products' capabilities.\n\n## 5. SUMMARY OF COLLECTED DATA\n\nIn summary, the data collected from the Mid-Mount Ladder Truck Equipment Evaluation provides a comprehensive overview of the performance, usability, and suitability of the evaluated products. Across the three categories, products from Dewalt, TNT, and Holmatro demonstrated high levels of quality and effectiveness, with consensus among evaluators on their strengths and weaknesses.\n\nAreas where more data may be needed include long-term durability testing and assessments of maintenance costs and spare part availability. However, the current data set provides a strong foundation for understanding the capabilities of the evaluated products and their potential to meet the Miami Beach Fire Department's equipment needs.\n\nThis report presents the analyzed data objectively, highlighting patterns, trends, and notable findings without making recommendations on procurement or next steps. The data will serve as a critical resource for informed decision-making, ensuring that any future equipment acquisitions align with the department's operational requirements and standards.";s:11:"sessionName";s:5:"Day 2";s:18:"categoriesAnalyzed";i:3;s:5:"model";s:31:"llama-3.3-70b-instruct-fp8-fast";s:11:"generatedAt";s:24:"2026-03-09T12:44:38.834Z";}	1773062078
build_sha	s:40:"5cf59c76d5fd410b1e20946ab563b64472a57edf";	1773060653
build_branch	s:4:"main";	1773060679
build_time	s:25:"2026-03-09T12:50:19+00:00";	1773060679
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: candidate_products; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.candidate_products (id, workgroup_session_id, category_id, name, manufacturer, model, description, created_at, updated_at) FROM stdin;
1	1	1	12-inch Cut-Off Saw	Dewalt	DCPS612AG2	According to DeWALT, this new addition is the most powerful 12 or 14-inch cordless cut-off saw in the entire industry, across power sources—battery or gas—which is a bold statement. The saw tackles tough jobs, including cuts up to 4-3/4-inch deep through concrete, ductile iron, rebar, and more. A single charge allows for upwards of 156 cuts in #5 rebar, or eight linear feet of concrete. 	2026-03-02 16:39:47	2026-03-02 16:39:47
2	1	2	Battery powered cutter	TNT	ESLC-29-20V	LENGTH 32.99 IN 837.90 MM\nHEIGHT 11.69 IN 296.83 MM\nWIDTH 10.50 IN 266.70 MM\nWEIGHT\nEXCL. BATTERY 51.40 LBS 23.31 KG\nINCL. BATTERY 54.60 LBS 24.76 KG\nMAX. BLADE OPENING 8.00 IN 203.00 MM\nMAX. CUT REACH 5.08 IN 129.15 MM\nPOWER SOURCE OPTION DEWALT 20V\nCERTIFIED TO NFPA 1936-2020 YES\nNFPA CUT TEST A8, B9, C9, D9, E9, F5\nIP RATING 54	2026-03-02 16:44:37	2026-03-02 16:44:37
3	1	2	Battery powered spreader	TNT	ES100-28-20V	LENGTH 34.80 IN 883.99 MM\nHEIGHT 11.15 IN 283.09 MM\nWIDTH 13.58 IN 344.93 MM\nWEIGHT\nEXCL. BATTERY 48.20 LBS 21.86 KG\nINCL. BATTERY 51,40 LBS 23.31 KG\nMAX. SPREAD DISTANCE 27.50 IN 698.50 MM\nMAX. SPREAD FORCE RANGE 56,128 - 228,126 LBF 249 - 1.014 KN\nMAX. PULL DISTANCE 18.25 IN 463.55 MM\nMAX. PULL FORCE 11,168 LBF 49.67 KN\nPOWER SOURCE OPTION DEWALT 20V\nCERTIFIED TO NFPA 1936-2020 YES\nIP RATING 54	2026-03-02 16:45:49	2026-03-02 16:45:49
4	1	2	Battery powered ram	TNT	ETLS-40-20V	CLOSED LENGTH 21.50 IN 546.10 MM\nEXTENDED LENGTH 42.25 IN 1073.15 MM\nHEIGHT 10.75 IN 273.00 MM\nWIDTH 8.10 IN 205.70 MM\nWEIGHT\nEXCL. BATTERY 38.75 LBS 17.57 KG\nINCL. BATTERY 41.95 LBS 19.02 KG\n1ST STAGE STROKE 12.95 IN 329.00 MM\n1ST STAGE MAX. PUSH FORCE 38.067 LBF 169.33 KN\n2ND STAGE STROKE 8.70 IN 221.00 MM\n2ND STAGE MAX. PUSH FORCE 13.088 LBF 58.21 KN\nPOWER SOURCE OPTION DEWALT 20V\nCERTIFIED TO NFPA 1936-2020 YES\nIP RATING 54	2026-03-02 16:47:10	2026-03-02 16:47:10
5	1	3	Vehicle Stabilization Strut	Holmatro	V-Strut	Vehicle stabilization strut with integrated head, base plate and tensioning belt with hook and ratchet mechanism.\nAll-in-one solution, no loose parts that may get lost.	2026-03-02 16:58:09	2026-03-02 16:58:09
6	4	2	Battery powered cutter	Holmatro	PCU30CL	Holmatro battery cutter.	2026-03-08 10:57:31	2026-03-08 10:57:31
7	4	2	Battery powered spreader	Holmatro	PSP40	Holmatro battery spreader.	2026-03-08 10:58:52	2026-03-08 10:58:52
8	4	2	Battery powered ram	Holmatro	PRA40	Holmatro battery Ram.	2026-03-08 10:59:42	2026-03-08 10:59:42
9	4	3	Omnishore	Holmatro	Strut	Holmatro Omnishore vehicle stabilization strut. 	2026-03-08 16:13:34	2026-03-08 16:13:34
10	4	1	T1	Holmatro	T1	Forcible entry tool. 	2026-03-08 16:14:29	2026-03-08 16:14:29
\.


--
-- Data for Name: capital_projects; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.capital_projects (id, name, description, budget_amount, start_date, target_completion_date, actual_completion, notes, created_at, updated_at, project_number, status, priority, ai_priority_rank, ai_priority_score, ai_reasoning, last_ai_analysis, percent_complete, attachments, station_id) FROM stdin;
41	FIRE STATION #4 – REPL. EXHAUST SYS	Replacement of vehicle exhaust system at Fire Station #4 to improve air quality and safety. Includes removal of old system, installation of new exhaust extraction equipment, and testing.	22946.00	2026-02-12	2026-07-29	\N	\N	2026-01-29 19:28:09	2026-01-29 19:28:09	66727	pending	medium	\N	\N	\N	\N	\N	\N	32
44	FIRE STATION #4 – ROOF REPLACEMENT	Critical roof replacement project for Fire Station #4. The existing roof has reached end of life and requires complete replacement to prevent water damage and maintain structural integrity. Highest priority infrastructure project.	357000.00	2026-01-29	2027-01-29	\N	\N	2026-01-29 19:28:09	2026-01-29 19:28:09	63731	pending	critical	\N	\N	\N	\N	\N	\N	32
47	FIRE STATION #4 – REPL. EXHAUST SYS	Secondary exhaust system replacement project for Fire Station #4 apparatus bay expansion. Complements the initial exhaust system project to cover additional bays.	177054.00	2026-04-29	2026-10-29	\N	\N	2026-01-29 19:28:09	2026-01-29 19:28:09	66727-B	pending	medium	\N	\N	\N	\N	\N	\N	32
42	FIRE STATION #1 – REPL. EXHAUST SYS	Complete replacement of the vehicle exhaust extraction system at Fire Station #1. This is a high-priority project to ensure firefighter health and safety by eliminating diesel exhaust exposure in the apparatus bay.	285000.00	2026-03-01	2026-10-29	\N	\N	2026-01-29 19:28:09	2026-01-29 19:28:09	67927	pending	high	\N	\N	\N	\N	\N	\N	29
43	FIRE STATION #2 – RESTROOM/PLUMBING	Major renovation of restroom facilities and plumbing infrastructure at Fire Station #2. Includes replacement of aging pipes, fixtures, ADA-compliant upgrades, and modernization of facilities.	255000.00	2026-02-19	2026-11-29	\N	\N	2026-01-29 19:28:09	2026-01-29 19:28:09	63631	pending	high	\N	\N	\N	\N	\N	\N	30
45	FIRE STATION #2 – REPL. EXHAUST SYS	Replacement of vehicle exhaust extraction system at Fire Station #2. Part of department-wide initiative to upgrade all station exhaust systems for improved air quality and firefighter health.	200000.00	2026-03-29	2026-09-29	\N	\N	2026-01-29 19:28:09	2026-01-29 19:28:09	65127	pending	medium	\N	\N	\N	\N	\N	\N	30
48	FIRE STATION #2 – VEHICLE AWNING REPL	Replacement of vehicle awning structure at Fire Station #2. The existing awning provides weather protection for apparatus and personnel during vehicle operations. Project includes structural improvements and modern materials.	237357.00	2026-03-01	2026-11-29	\N	\N	2026-01-29 19:28:09	2026-01-29 19:28:09	60626	pending	high	\N	\N	\N	\N	\N	\N	30
46	FIRE STATION #3 – REPL. EXHAUST SYS	Replacement of vehicle exhaust extraction system at Fire Station #3. This project will eliminate diesel exhaust exposure in the apparatus bay and improve overall air quality for personnel.	228000.00	2026-03-29	2026-09-29	\N	\N	2026-01-29 19:28:09	2026-01-29 19:28:09	66527	pending	medium	\N	\N	\N	\N	\N	\N	31
\.


--
-- Data for Name: ch_favorites; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.ch_favorites (id, user_id, friend_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: ch_messages; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.ch_messages (id, from_id, to_id, body, attachment, seen, created_at, updated_at) FROM stdin;
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
-- Data for Name: evaluation_categories; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.evaluation_categories (id, name, description, is_rankable, display_order, is_active, created_at, updated_at, assessment_profile, instructions_markdown, score_visibility_notes, finalists_limit) FROM stdin;
1	Forcible Entry Tools	Powered forcible entry and ventilation tools.	t	0	t	2026-03-02 14:31:10	2026-03-02 14:31:10	hand_tool_forcible	\N	\N	\N
2	Battery-Operated Extrication Tools	Battery operated cutters, spreaders, rams, combination tools, etc.	t	0	t	2026-03-02 16:42:25	2026-03-02 16:42:25	powered_tool	\N	\N	\N
3	Vehicle Stabilization	Vehicle stabilization struts with and without lifting capabilities. 	t	0	t	2026-03-02 16:57:09	2026-03-02 16:57:09	stabilization_support	\N	\N	\N
\.


--
-- Data for Name: evaluation_comments; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.evaluation_comments (id, submission_id, category_id, comment, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: evaluation_criteria; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.evaluation_criteria (id, template_id, name, description, max_score, weight, display_order, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: evaluation_scores; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.evaluation_scores (id, submission_id, criterion_id, score, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: evaluation_submissions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.evaluation_submissions (id, workgroup_member_id, candidate_product_id, status, submitted_at, created_at, updated_at, rubric_version, assessment_profile, overall_score, capability_score, usability_score, affordability_score, maintainability_score, deployability_score, advance_recommendation, confidence_level, has_deal_breaker, deal_breaker_note, criterion_payload, narrative_payload, is_locked, session_id, user_id) FROM stdin;
16	5	3	submitted	2026-03-05 15:24:09	2026-03-05 15:23:21	2026-03-05 15:24:09	v1_universal_apparatus	powered_tool	100.00	100.00	100.00	100.00	100.00	100.00	yes	high	f	\N	{"notes": [], "ratings": {"use_ppe": 5, "aff_value": 5, "pwr_safety": 5, "dep_storage": 5, "pwr_battery": 5, "dep_readiness": 5, "maint_support": 5, "cap_durability": 5, "maint_training": 5, "use_ergonomics": 5, "aff_commonality": 5, "cap_versatility": 5, "pwr_performance": 5, "use_portability": 5, "cap_effectiveness": 5}}	{"best_use_case": null, "safety_concerns": null, "biggest_weaknesses": null, "additional_comments": null, "strongest_advantages": null}	f	\N	\N
12	7	1	submitted	2026-03-04 02:46:54	2026-03-04 02:23:27	2026-03-04 02:46:54	v1_universal_apparatus	hand_tool_forcible	100.00	100.00	100.00	100.00	100.00	100.00	yes	high	f	\N	{"notes": [], "ratings": {"use_ppe": 5, "aff_value": 5, "dep_storage": 5, "dep_readiness": 5, "maint_support": 5, "cap_durability": 5, "hnd_durability": 5, "maint_training": 5, "use_ergonomics": 5, "aff_commonality": 5, "cap_versatility": 5, "use_portability": 5, "cap_effectiveness": 5, "hnd_effectiveness": 5}}	{"best_use_case": null, "safety_concerns": null, "biggest_weaknesses": null, "additional_comments": null, "strongest_advantages": null}	f	\N	3
14	7	2	draft	\N	2026-03-04 17:45:51	2026-03-04 17:45:51	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	\N	\N	\N	f	\N	\N
15	5	5	draft	\N	2026-03-05 15:22:12	2026-03-05 15:22:12	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	\N	\N	\N	f	\N	\N
11	5	1	draft	\N	2026-03-03 13:54:44	2026-03-05 15:23:09	v1_universal_apparatus	hand_tool_forcible	100.00	100.00	100.00	100.00	100.00	100.00	\N	\N	f	\N	{"notes": [], "ratings": {"use_ppe": 5, "aff_value": 5, "dep_storage": 5, "dep_readiness": 5, "maint_support": 5, "cap_durability": 5, "hnd_durability": 5, "maint_training": 5, "use_ergonomics": 5, "aff_commonality": 5, "cap_versatility": 5, "use_portability": 5, "cap_effectiveness": 5, "hnd_effectiveness": 5}}	{"best_use_case": "NA", "safety_concerns": "NA", "biggest_weaknesses": "NA", "additional_comments": "NA", "strongest_advantages": "NA"}	f	\N	6
21	16	5	draft	\N	2026-03-06 14:35:39	2026-03-06 14:41:03	v1_universal_apparatus	stabilization_support	62.86	100.00	92.86	0.00	0.00	100.00	yes	high	f	\N	{"notes": [], "ratings": {"use_ppe": "4", "aff_value": "n/a", "stb_safety": "5", "dep_storage": "5", "stb_strength": "5", "dep_readiness": "5", "maint_support": "n/a", "cap_durability": "5", "maint_training": "n/a", "use_ergonomics": "5", "aff_commonality": "n/a", "cap_versatility": "5", "use_portability": "5", "cap_effectiveness": "5"}}	{"best_use_case": null, "safety_concerns": null, "biggest_weaknesses": "", "additional_comments": null, "strongest_advantages": ""}	f	\N	\N
22	16	1	draft	\N	2026-03-06 14:54:43	2026-03-06 14:57:08	v1_universal_apparatus	hand_tool_forcible	80.00	100.00	100.00	0.00	100.00	100.00	yes	high	f	\N	{"notes": [], "ratings": {"use_ppe": 5, "aff_value": "n/a", "dep_storage": 5, "dep_readiness": 5, "maint_support": "n/a", "cap_durability": 5, "hnd_durability": 5, "maint_training": 5, "use_ergonomics": 5, "aff_commonality": "n/a", "cap_versatility": 5, "use_portability": 5, "cap_effectiveness": 5, "hnd_effectiveness": 5}}	{"best_use_case": null, "safety_concerns": null, "biggest_weaknesses": null, "additional_comments": "10/10", "strongest_advantages": "Will work in any environment. 14” cut depth in a 12” saw"}	f	\N	\N
23	16	2	draft	\N	2026-03-06 14:57:28	2026-03-06 14:57:28	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	\N	\N	\N	f	\N	\N
24	4	1	submitted	2026-03-06 16:03:52	2026-03-06 15:42:07	2026-03-06 16:03:52	v1_universal_apparatus	hand_tool_forcible	87.48	92.86	80.00	100.00	80.00	72.50	yes	high	f	\N	{"notes": {"use_ppe": "it can be operated fine with PPE, confined spaces is a vague review. its a large saw. it may not be a first option in a confined space but it functioned well for what it should be used for.", "dep_storage": "Very similar size to our existing saw", "dep_readiness": "", "cap_durability": "When comparing cutting strength through different materials it did well. Its not possible to grade long term durability.", "hnd_durability": "This needs to be tested with time and use.", "maint_training": "Minimal maintenance required a side from cleaning.", "use_ergonomics": "Easy to use, it was not neccesarily lighter but controls were straight forward.", "aff_commonality": "Same brand as other tools we carry", "cap_versatility": "Same batteries as other tools and no longer need to carry around fuel containers.", "use_portability": "Similar to previous notes. Its similar in weight to what we have, so transport capabilities are the same. Position and control at scene it has benefits as it can be layed down in any position with any effect, and has a built in brake that does not allow a free spinning blades after use.", "cap_effectiveness": "Saw seemed equally powerful and capable relative to a gas saw with similar weight. The added advantage of no maintenance, operable in low oxygen environment, and inability to be flooded or have difficulty starting makes it a more effective option relative to any gas saw.", "hnd_effectiveness": "Only able to test it as a forcible entry on cars. I preformed well and as expected. No tests were made on door, windows or garage doors so testing is limited."}, "ratings": {"use_ppe": "4", "aff_value": "n/a", "dep_storage": "3", "dep_readiness": "4", "maint_support": "n/a", "cap_durability": "4", "hnd_durability": "n/a", "maint_training": "4", "use_ergonomics": "4", "aff_commonality": "5", "cap_versatility": "5", "use_portability": "4", "cap_effectiveness": "5", "hnd_effectiveness": "4"}}	{"best_use_case": "No difference from current saw with less limitations.", "safety_concerns": "Motor and battery have a potential to overheat with prolonged use under a load which will result in the saw shutting down to protect itself. ", "biggest_weaknesses": "Slightly heavier, unknown on battery longevity and reliability. ", "additional_comments": null, "strongest_advantages": "No more need for fuel which frees up space in compartments, can be moved and rotated in any direction, cant be flooded, can run in a smoke filled environment."}	f	\N	\N
25	4	5	submitted	2026-03-06 16:13:20	2026-03-06 16:04:04	2026-03-06 16:13:20	v1_universal_apparatus	stabilization_support	94.29	94.29	100.00	80.00	100.00	100.00	yes	high	f	\N	{"notes": {"stb_strength": "", "use_ergonomics": "Light and simple", "cap_versatility": "Relative to current struts it is more limited in capabilities as it doesn't lift. Can with with all existing equipment. ", "cap_effectiveness": "Fast, simple, and efficient. Basic but great at functionality for what it does."}, "ratings": {"use_ppe": "5", "aff_value": "n/a", "stb_safety": "4", "dep_storage": "5", "stb_strength": "5", "dep_readiness": "5", "maint_support": "n/a", "cap_durability": "5", "maint_training": "5", "use_ergonomics": "5", "aff_commonality": "4", "cap_versatility": "4", "use_portability": "5", "cap_effectiveness": "5"}}	{"best_use_case": "Only use as stabilization.", "safety_concerns": "N/a", "biggest_weaknesses": "Lacks ability to used to lift.", "additional_comments": null, "strongest_advantages": "Very light and portable. minimal steps for deployment, simple and efficient deployment. \\"Almost not very smart people proof\\""}	f	\N	\N
26	4	2	submitted	2026-03-06 16:20:36	2026-03-06 16:13:39	2026-03-06 16:20:36	v1_universal_apparatus	powered_tool	90.15	92.86	80.00	100.00	91.11	92.50	yes	high	f	\N	{"notes": [], "ratings": {"use_ppe": "4", "aff_value": "n/a", "pwr_safety": "4", "dep_storage": "4", "pwr_battery": "5", "dep_readiness": "5", "maint_support": "5", "cap_durability": "5", "maint_training": "4", "use_ergonomics": "4", "aff_commonality": "5", "cap_versatility": "5", "pwr_performance": "5", "use_portability": "4", "cap_effectiveness": "4"}}	{"best_use_case": "Extrication", "safety_concerns": "Lack of hands on training with members on the ladder companies actually making cuts and accessing patients in cars.", "biggest_weaknesses": "Slightly bulkier than tethered units. Angle of cutting blades make the blades some times slip on cuts.", "additional_comments": null, "strongest_advantages": "Rapid deployment, familiarity since we already use TNT, easier to handle over tethered units, no need for power plan."}	f	\N	\N
27	4	4	submitted	2026-03-06 16:23:31	2026-03-06 16:20:47	2026-03-06 16:23:31	v1_universal_apparatus	powered_tool	95.77	100.00	87.14	100.00	100.00	92.50	yes	high	f	\N	{"notes": [], "ratings": {"use_ppe": "5", "aff_value": "n/a", "pwr_safety": "4", "dep_storage": "4", "pwr_battery": "5", "dep_readiness": "5", "maint_support": "5", "cap_durability": "5", "maint_training": "5", "use_ergonomics": "4", "aff_commonality": "5", "cap_versatility": "5", "pwr_performance": "5", "use_portability": "4", "cap_effectiveness": "5"}}	{"best_use_case": "extrication", "safety_concerns": null, "biggest_weaknesses": "Relative to the cutters the ram is even more bulky compared to its compatible tethered unit.", "additional_comments": null, "strongest_advantages": "Same as cutters."}	f	\N	\N
28	4	3	submitted	2026-03-06 16:26:06	2026-03-06 16:23:42	2026-03-06 16:26:06	v1_universal_apparatus	powered_tool	94.43	100.00	87.14	100.00	91.11	92.50	yes	high	f	\N	{"notes": [], "ratings": {"use_ppe": "5", "aff_value": "n/a", "pwr_safety": "4", "dep_storage": "4", "pwr_battery": "5", "dep_readiness": "5", "maint_support": "5", "cap_durability": "5", "maint_training": "4", "use_ergonomics": "4", "aff_commonality": "5", "cap_versatility": "5", "pwr_performance": "5", "use_portability": "4", "cap_effectiveness": "5"}}	{"best_use_case": "extrication", "safety_concerns": null, "biggest_weaknesses": "Slighty bulkier then tethered unit.", "additional_comments": null, "strongest_advantages": "Same as cutters. "}	f	\N	\N
29	10	1	submitted	2026-03-09 00:25:39	2026-03-09 00:20:28	2026-03-09 00:25:39	v1_universal_apparatus	hand_tool_forcible	93.64	100.00	87.14	87.50	100.00	100.00	yes	high	f	\N	{"notes": {"use_ppe": "slightly heavier than gas", "cap_effectiveness": "Great saw. liked how it slowed down when trigger is released "}, "ratings": {"use_ppe": "4", "aff_value": "4", "dep_storage": 5, "dep_readiness": 5, "maint_support": 5, "cap_durability": 5, "hnd_durability": 5, "maint_training": 5, "use_ergonomics": 5, "aff_commonality": 5, "cap_versatility": 5, "use_portability": "4", "cap_effectiveness": 5, "hnd_effectiveness": 5}}	{"best_use_case": "all around k saw same use as gas ", "safety_concerns": "same as any k saw", "biggest_weaknesses": "weight ", "additional_comments": null, "strongest_advantages": "no chance of flooding or ignition problems due to fuel or smoke \\nless noise "}	f	\N	\N
30	10	2	submitted	2026-03-09 00:35:47	2026-03-09 00:25:56	2026-03-09 00:35:47	v1_universal_apparatus	powered_tool	78.72	85.71	85.71	60.00	71.11	92.50	yes	high	f	\N	{"notes": {"pwr_safety": "no emergency stop in case the tool pinches operator ", "cap_durability": ""}, "ratings": {"use_ppe": "4", "aff_value": "3", "pwr_safety": "3", "dep_storage": "4", "pwr_battery": "4", "dep_readiness": 5, "maint_support": "4", "cap_durability": "3", "maint_training": "3", "use_ergonomics": "4", "aff_commonality": "3", "cap_versatility": 5, "pwr_performance": "4", "use_portability": 5, "cap_effectiveness": 5}}	{"best_use_case": null, "safety_concerns": null, "biggest_weaknesses": "cutter tips ", "additional_comments": "housing for the ram was cumbersome bulky ", "strongest_advantages": "has been on E-2. crews are comfortable with them "}	f	\N	\N
31	10	4	submitted	2026-03-09 00:39:32	2026-03-09 00:35:57	2026-03-09 00:39:32	v1_universal_apparatus	powered_tool	79.67	67.14	92.86	80.00	71.11	100.00	yes	high	f	\N	{"notes": {"cap_durability": "housing was bulky and cumbersome and was found to be leaking "}, "ratings": {"use_ppe": "5", "aff_value": "4", "pwr_safety": "3", "dep_storage": "5", "pwr_battery": "3", "dep_readiness": "5", "maint_support": "4", "cap_durability": "3", "maint_training": "3", "use_ergonomics": "4", "aff_commonality": "4", "cap_versatility": "3", "pwr_performance": "4", "use_portability": "5", "cap_effectiveness": "4"}}	{"best_use_case": "all extrication scenarios ", "safety_concerns": null, "biggest_weaknesses": "milwakee battery \\nwater resistant not proof ", "additional_comments": "not sure if we have loaner options while repairs are being done ", "strongest_advantages": "E-2 has them personnel are comfortable with them.\\nlocal company "}	f	\N	\N
32	10	3	submitted	2026-03-09 00:43:00	2026-03-09 00:39:53	2026-03-09 00:43:00	v1_universal_apparatus	powered_tool	79.14	80.00	87.14	60.00	80.00	100.00	yes	medium	f	\N	{"notes": {"cap_effectiveness": "dont like the current tips. causetool to torque and turn as it bites "}, "ratings": {"use_ppe": "5", "aff_value": "3", "pwr_safety": "3", "dep_storage": "5", "pwr_battery": "3", "dep_readiness": "5", "maint_support": "4", "cap_durability": "5", "maint_training": "4", "use_ergonomics": "4", "aff_commonality": "3", "cap_versatility": "4", "pwr_performance": "4", "use_portability": "4", "cap_effectiveness": "3"}}	{"best_use_case": "extrication", "safety_concerns": "no emergency stop", "biggest_weaknesses": "cutter tip shape was not the best torqued and turned while cutting ", "additional_comments": null, "strongest_advantages": "e-2 already has them \\n"}	f	\N	\N
33	10	5	submitted	2026-03-09 00:47:06	2026-03-09 00:43:10	2026-03-09 00:47:06	v1_universal_apparatus	stabilization_support	86.25	74.29	100.00	80.00	88.89	92.50	yes	medium	f	\N	{"notes": {"cap_versatility": "I prefer a strut that can lift as well as stabilize "}, "ratings": {"use_ppe": "5", "aff_value": "4", "stb_safety": "4", "dep_storage": "4", "stb_strength": "4", "dep_readiness": "5", "maint_support": "4", "cap_durability": "4", "maint_training": "5", "use_ergonomics": "5", "aff_commonality": "n/a", "cap_versatility": "3", "use_portability": "5", "cap_effectiveness": "4"}}	{"best_use_case": "stabilize ", "safety_concerns": "", "biggest_weaknesses": "Doesnt lift !!!!\\n", "additional_comments": "Is a good tool to have but should not discard the possibility of a lifting strut ", "strongest_advantages": "light \\nfast \\neasy storage"}	f	\N	\N
34	5	10	draft	\N	2026-03-09 01:06:14	2026-03-09 01:06:14	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	\N	\N	\N	f	\N	\N
35	7	10	draft	\N	2026-03-09 11:55:43	2026-03-09 11:55:43	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	\N	\N	\N	f	\N	\N
\.


--
-- Data for Name: evaluation_templates; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.evaluation_templates (id, name, description, category_id, is_active, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: external_nav_items; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.external_nav_items (id, division, label, slug, type, url, external_source_id, baserow_workspace_id, baserow_database_id, baserow_table_id, baserow_view_id, allowed_roles, allowed_permissions, sort_order, is_active, open_in_new_tab, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: external_sources; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.external_sources (id, division, name, provider, base_url, token_encrypted, token_hint, status, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
1	f8d4d63c-b4bb-4dc4-9435-c8c638a93466	database	default	{"uuid":"f8d4d63c-b4bb-4dc4-9435-c8c638a93466","displayName":"App\\\\Jobs\\\\SyncApparatusToSheetJob","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":3,"maxExceptions":null,"failOnTimeout":false,"backoff":"60","timeout":null,"retryUntil":null,"data":{"commandName":"App\\\\Jobs\\\\SyncApparatusToSheetJob","command":"O:32:\\"App\\\\Jobs\\\\SyncApparatusToSheetJob\\":0:{}"},"sentry_baggage_data":"sentry-trace_id=0a819678f5214ed189cfb4e213727d14,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.resources.apparatus-resource.pages.list-apparatuses,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.458574","sentry_trace_parent_data":"0a819678f5214ed189cfb4e213727d14-30f2ec65636d4433-0","sentry_publish_time":1773056722.573136}	Error: Class "Google\\Client" not found in /var/www/html/app/Services/GoogleSheets/ApparatusSheetSyncService.php:143\nStack trace:\n#0 /var/www/html/app/Services/GoogleSheets/ApparatusSheetSyncService.php(37): App\\Services\\GoogleSheets\\ApparatusSheetSyncService->bootClient()\n#1 /var/www/html/app/Jobs/SyncApparatusToSheetJob.php(29): App\\Services\\GoogleSheets\\ApparatusSheetSyncService->sync()\n#2 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): App\\Jobs\\SyncApparatusToSheetJob->handle()\n#3 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#4 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#5 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#6 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#7 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(126): Illuminate\\Container\\Container->call()\n#8 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Bus\\Dispatcher->{closure:Illuminate\\Bus\\Dispatcher::dispatchNow():123}()\n#9 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#10 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(130): Illuminate\\Pipeline\\Pipeline->then()\n#11 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(126): Illuminate\\Bus\\Dispatcher->dispatchNow()\n#12 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Queue\\CallQueuedHandler->{closure:Illuminate\\Queue\\CallQueuedHandler::dispatchThroughMiddleware():121}()\n#13 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#14 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(121): Illuminate\\Pipeline\\Pipeline->then()\n#15 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(69): Illuminate\\Queue\\CallQueuedHandler->dispatchThroughMiddleware()\n#16 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call()\n#17 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(442): Illuminate\\Queue\\Jobs\\Job->fire()\n#18 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(392): Illuminate\\Queue\\Worker->process()\n#19 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(335): Illuminate\\Queue\\Worker->runJob()\n#20 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(149): Illuminate\\Queue\\Worker->runNextJob()\n#21 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker()\n#22 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#23 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#24 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#25 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#26 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#27 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(213): Illuminate\\Container\\Container->call()\n#28 /var/www/html/vendor/symfony/console/Command/Command.php(341): Illuminate\\Console\\Command->execute()\n#29 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(182): Symfony\\Component\\Console\\Command\\Command->run()\n#30 /var/www/html/vendor/symfony/console/Application.php(1102): Illuminate\\Console\\Command->run()\n#31 /var/www/html/vendor/symfony/console/Application.php(356): Symfony\\Component\\Console\\Application->doRunCommand()\n#32 /var/www/html/vendor/symfony/console/Application.php(195): Symfony\\Component\\Console\\Application->doRun()\n#33 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Console/Kernel.php(198): Symfony\\Component\\Console\\Application->run()\n#34 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle()\n#35 /var/www/html/artisan(13): Illuminate\\Foundation\\Application->handleCommand()\n#36 {main}	2026-03-09 12:38:33
2	9da15ebf-2d59-4286-8bf8-4d04a2f905b9	database	default	{"uuid":"9da15ebf-2d59-4286-8bf8-4d04a2f905b9","displayName":"App\\\\Listeners\\\\SendEvaluationToAiWorker","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":2,"maxExceptions":null,"failOnTimeout":false,"backoff":"30","timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Events\\\\CallQueuedListener","command":"O:36:\\"Illuminate\\\\Events\\\\CallQueuedListener\\":20:{s:5:\\"class\\";s:38:\\"App\\\\Listeners\\\\SendEvaluationToAiWorker\\";s:6:\\"method\\";s:6:\\"handle\\";s:4:\\"data\\";a:1:{i:0;O:30:\\"App\\\\Events\\\\EvaluationSubmitted\\":1:{s:10:\\"submission\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:31:\\"App\\\\Models\\\\EvaluationSubmission\\";s:2:\\"id\\";i:12;s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}}}s:5:\\"tries\\";i:2;s:13:\\"maxExceptions\\";N;s:7:\\"backoff\\";i:30;s:10:\\"retryUntil\\";N;s:7:\\"timeout\\";N;s:13:\\"failOnTimeout\\";b:0;s:17:\\"shouldBeEncrypted\\";b:0;s:3:\\"job\\";N;s:10:\\"connection\\";N;s:5:\\"queue\\";N;s:5:\\"delay\\";N;s:11:\\"afterCommit\\";N;s:10:\\"middleware\\";a:0:{}s:7:\\"chained\\";a:0:{}s:15:\\"chainConnection\\";N;s:10:\\"chainQueue\\";N;s:19:\\"chainCatchCallbacks\\";N;}"},"sentry_baggage_data":"sentry-trace_id=14c8e25f0d06478db3c2a6edfc104acd,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.workgroup.pages.evaluation-form-page,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.354421","sentry_trace_parent_data":"14c8e25f0d06478db3c2a6edfc104acd-e5a185009fb74692-0","sentry_publish_time":1772592414.200928}	ReflectionException: Class "App\\Listeners\\SendEvaluationToAiWorker" does not exist in /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php:959\nStack trace:\n#0 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(959): ReflectionClass->__construct()\n#1 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(832): Illuminate\\Container\\Container->build()\n#2 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1078): Illuminate\\Container\\Container->resolve()\n#3 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(763): Illuminate\\Foundation\\Application->resolve()\n#4 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1058): Illuminate\\Container\\Container->make()\n#5 /var/www/html/vendor/laravel/framework/src/Illuminate/Events/CallQueuedListener.php(111): Illuminate\\Foundation\\Application->make()\n#6 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Events\\CallQueuedListener->handle()\n#7 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#8 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#9 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#10 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#11 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(126): Illuminate\\Container\\Container->call()\n#12 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Bus\\Dispatcher->{closure:Illuminate\\Bus\\Dispatcher::dispatchNow():123}()\n#13 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#14 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(130): Illuminate\\Pipeline\\Pipeline->then()\n#15 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(126): Illuminate\\Bus\\Dispatcher->dispatchNow()\n#16 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Queue\\CallQueuedHandler->{closure:Illuminate\\Queue\\CallQueuedHandler::dispatchThroughMiddleware():121}()\n#17 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#18 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(121): Illuminate\\Pipeline\\Pipeline->then()\n#19 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(69): Illuminate\\Queue\\CallQueuedHandler->dispatchThroughMiddleware()\n#20 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call()\n#21 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(442): Illuminate\\Queue\\Jobs\\Job->fire()\n#22 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(392): Illuminate\\Queue\\Worker->process()\n#23 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(178): Illuminate\\Queue\\Worker->runJob()\n#24 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon()\n#25 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker()\n#26 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#27 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#28 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#29 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#30 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#31 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(213): Illuminate\\Container\\Container->call()\n#32 /var/www/html/vendor/symfony/console/Command/Command.php(341): Illuminate\\Console\\Command->execute()\n#33 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(182): Symfony\\Component\\Console\\Command\\Command->run()\n#34 /var/www/html/vendor/symfony/console/Application.php(1102): Illuminate\\Console\\Command->run()\n#35 /var/www/html/vendor/symfony/console/Application.php(356): Symfony\\Component\\Console\\Application->doRunCommand()\n#36 /var/www/html/vendor/symfony/console/Application.php(195): Symfony\\Component\\Console\\Application->doRun()\n#37 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Console/Kernel.php(198): Symfony\\Component\\Console\\Application->run()\n#38 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle()\n#39 /var/www/html/artisan(13): Illuminate\\Foundation\\Application->handleCommand()\n#40 {main}\n\nNext Illuminate\\Contracts\\Container\\BindingResolutionException: Target class [App\\Listeners\\SendEvaluationToAiWorker] does not exist. in /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php:961\nStack trace:\n#0 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(832): Illuminate\\Container\\Container->build()\n#1 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1078): Illuminate\\Container\\Container->resolve()\n#2 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(763): Illuminate\\Foundation\\Application->resolve()\n#3 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1058): Illuminate\\Container\\Container->make()\n#4 /var/www/html/vendor/laravel/framework/src/Illuminate/Events/CallQueuedListener.php(111): Illuminate\\Foundation\\Application->make()\n#5 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Events\\CallQueuedListener->handle()\n#6 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#7 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#8 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#9 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#10 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(126): Illuminate\\Container\\Container->call()\n#11 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Bus\\Dispatcher->{closure:Illuminate\\Bus\\Dispatcher::dispatchNow():123}()\n#12 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#13 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(130): Illuminate\\Pipeline\\Pipeline->then()\n#14 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(126): Illuminate\\Bus\\Dispatcher->dispatchNow()\n#15 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Queue\\CallQueuedHandler->{closure:Illuminate\\Queue\\CallQueuedHandler::dispatchThroughMiddleware():121}()\n#16 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#17 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(121): Illuminate\\Pipeline\\Pipeline->then()\n#18 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(69): Illuminate\\Queue\\CallQueuedHandler->dispatchThroughMiddleware()\n#19 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call()\n#20 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(442): Illuminate\\Queue\\Jobs\\Job->fire()\n#21 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(392): Illuminate\\Queue\\Worker->process()\n#22 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(178): Illuminate\\Queue\\Worker->runJob()\n#23 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon()\n#24 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker()\n#25 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#26 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#27 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#28 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#29 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#30 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(213): Illuminate\\Container\\Container->call()\n#31 /var/www/html/vendor/symfony/console/Command/Command.php(341): Illuminate\\Console\\Command->execute()\n#32 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(182): Symfony\\Component\\Console\\Command\\Command->run()\n#33 /var/www/html/vendor/symfony/console/Application.php(1102): Illuminate\\Console\\Command->run()\n#34 /var/www/html/vendor/symfony/console/Application.php(356): Symfony\\Component\\Console\\Application->doRunCommand()\n#35 /var/www/html/vendor/symfony/console/Application.php(195): Symfony\\Component\\Console\\Application->doRun()\n#36 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Console/Kernel.php(198): Symfony\\Component\\Console\\Application->run()\n#37 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle()\n#38 /var/www/html/artisan(13): Illuminate\\Foundation\\Application->handleCommand()\n#39 {main}	2026-03-09 12:49:21
3	7de53f8f-282c-4b43-83d7-02798a953c7a	database	default	{"uuid":"7de53f8f-282c-4b43-83d7-02798a953c7a","displayName":"App\\\\Listeners\\\\SendEvaluationToAiWorker","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":2,"maxExceptions":null,"failOnTimeout":false,"backoff":"30","timeout":null,"retryUntil":null,"data":{"commandName":"Illuminate\\\\Events\\\\CallQueuedListener","command":"O:36:\\"Illuminate\\\\Events\\\\CallQueuedListener\\":20:{s:5:\\"class\\";s:38:\\"App\\\\Listeners\\\\SendEvaluationToAiWorker\\";s:6:\\"method\\";s:6:\\"handle\\";s:4:\\"data\\";a:1:{i:0;O:30:\\"App\\\\Events\\\\EvaluationSubmitted\\":1:{s:10:\\"submission\\";O:45:\\"Illuminate\\\\Contracts\\\\Database\\\\ModelIdentifier\\":5:{s:5:\\"class\\";s:31:\\"App\\\\Models\\\\EvaluationSubmission\\";s:2:\\"id\\";i:12;s:9:\\"relations\\";a:0:{}s:10:\\"connection\\";s:5:\\"pgsql\\";s:15:\\"collectionClass\\";N;}}}s:5:\\"tries\\";i:2;s:13:\\"maxExceptions\\";N;s:7:\\"backoff\\";i:30;s:10:\\"retryUntil\\";N;s:7:\\"timeout\\";N;s:13:\\"failOnTimeout\\";b:0;s:17:\\"shouldBeEncrypted\\";b:0;s:3:\\"job\\";N;s:10:\\"connection\\";N;s:5:\\"queue\\";N;s:5:\\"delay\\";N;s:11:\\"afterCommit\\";N;s:10:\\"middleware\\";a:0:{}s:7:\\"chained\\";a:0:{}s:15:\\"chainConnection\\";N;s:10:\\"chainQueue\\";N;s:19:\\"chainCatchCallbacks\\";N;}"},"sentry_baggage_data":"sentry-trace_id=14c8e25f0d06478db3c2a6edfc104acd,sentry-sample_rate=0.1,sentry-transaction=livewire%3Fcomponent%3Dapp.filament.workgroup.pages.evaluation-form-page,sentry-public_key=5c59915d36fe82b8f8db7d37c5bb4c0f,sentry-org_id=4510757508481024,sentry-environment=production,sentry-sampled=false,sentry-sample_rand=0.354421","sentry_trace_parent_data":"14c8e25f0d06478db3c2a6edfc104acd-e5a185009fb74692-0","sentry_publish_time":1772592414.206666}	ReflectionException: Class "App\\Listeners\\SendEvaluationToAiWorker" does not exist in /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php:959\nStack trace:\n#0 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(959): ReflectionClass->__construct()\n#1 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(832): Illuminate\\Container\\Container->build()\n#2 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1078): Illuminate\\Container\\Container->resolve()\n#3 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(763): Illuminate\\Foundation\\Application->resolve()\n#4 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1058): Illuminate\\Container\\Container->make()\n#5 /var/www/html/vendor/laravel/framework/src/Illuminate/Events/CallQueuedListener.php(111): Illuminate\\Foundation\\Application->make()\n#6 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Events\\CallQueuedListener->handle()\n#7 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#8 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#9 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#10 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#11 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(126): Illuminate\\Container\\Container->call()\n#12 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Bus\\Dispatcher->{closure:Illuminate\\Bus\\Dispatcher::dispatchNow():123}()\n#13 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#14 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(130): Illuminate\\Pipeline\\Pipeline->then()\n#15 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(126): Illuminate\\Bus\\Dispatcher->dispatchNow()\n#16 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Queue\\CallQueuedHandler->{closure:Illuminate\\Queue\\CallQueuedHandler::dispatchThroughMiddleware():121}()\n#17 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#18 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(121): Illuminate\\Pipeline\\Pipeline->then()\n#19 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(69): Illuminate\\Queue\\CallQueuedHandler->dispatchThroughMiddleware()\n#20 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call()\n#21 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(442): Illuminate\\Queue\\Jobs\\Job->fire()\n#22 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(392): Illuminate\\Queue\\Worker->process()\n#23 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(178): Illuminate\\Queue\\Worker->runJob()\n#24 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon()\n#25 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker()\n#26 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#27 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#28 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#29 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#30 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#31 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(213): Illuminate\\Container\\Container->call()\n#32 /var/www/html/vendor/symfony/console/Command/Command.php(341): Illuminate\\Console\\Command->execute()\n#33 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(182): Symfony\\Component\\Console\\Command\\Command->run()\n#34 /var/www/html/vendor/symfony/console/Application.php(1102): Illuminate\\Console\\Command->run()\n#35 /var/www/html/vendor/symfony/console/Application.php(356): Symfony\\Component\\Console\\Application->doRunCommand()\n#36 /var/www/html/vendor/symfony/console/Application.php(195): Symfony\\Component\\Console\\Application->doRun()\n#37 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Console/Kernel.php(198): Symfony\\Component\\Console\\Application->run()\n#38 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle()\n#39 /var/www/html/artisan(13): Illuminate\\Foundation\\Application->handleCommand()\n#40 {main}\n\nNext Illuminate\\Contracts\\Container\\BindingResolutionException: Target class [App\\Listeners\\SendEvaluationToAiWorker] does not exist. in /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php:961\nStack trace:\n#0 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(832): Illuminate\\Container\\Container->build()\n#1 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1078): Illuminate\\Container\\Container->resolve()\n#2 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(763): Illuminate\\Foundation\\Application->resolve()\n#3 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1058): Illuminate\\Container\\Container->make()\n#4 /var/www/html/vendor/laravel/framework/src/Illuminate/Events/CallQueuedListener.php(111): Illuminate\\Foundation\\Application->make()\n#5 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Events\\CallQueuedListener->handle()\n#6 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#7 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#8 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#9 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#10 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(126): Illuminate\\Container\\Container->call()\n#11 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Bus\\Dispatcher->{closure:Illuminate\\Bus\\Dispatcher::dispatchNow():123}()\n#12 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#13 /var/www/html/vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php(130): Illuminate\\Pipeline\\Pipeline->then()\n#14 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(126): Illuminate\\Bus\\Dispatcher->dispatchNow()\n#15 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(170): Illuminate\\Queue\\CallQueuedHandler->{closure:Illuminate\\Queue\\CallQueuedHandler::dispatchThroughMiddleware():121}()\n#16 /var/www/html/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(127): Illuminate\\Pipeline\\Pipeline->{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():168}()\n#17 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(121): Illuminate\\Pipeline\\Pipeline->then()\n#18 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php(69): Illuminate\\Queue\\CallQueuedHandler->dispatchThroughMiddleware()\n#19 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call()\n#20 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(442): Illuminate\\Queue\\Jobs\\Job->fire()\n#21 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(392): Illuminate\\Queue\\Worker->process()\n#22 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(178): Illuminate\\Queue\\Worker->runJob()\n#23 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(149): Illuminate\\Queue\\Worker->daemon()\n#24 /var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php(132): Illuminate\\Queue\\Console\\WorkCommand->runWorker()\n#25 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#26 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php(43): Illuminate\\Container\\BoundMethod::{closure:Illuminate\\Container\\BoundMethod::call():35}()\n#27 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(95): Illuminate\\Container\\Util::unwrapIfClosure()\n#28 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod()\n#29 /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(696): Illuminate\\Container\\BoundMethod::call()\n#30 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(213): Illuminate\\Container\\Container->call()\n#31 /var/www/html/vendor/symfony/console/Command/Command.php(341): Illuminate\\Console\\Command->execute()\n#32 /var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php(182): Symfony\\Component\\Console\\Command\\Command->run()\n#33 /var/www/html/vendor/symfony/console/Application.php(1102): Illuminate\\Console\\Command->run()\n#34 /var/www/html/vendor/symfony/console/Application.php(356): Symfony\\Component\\Console\\Application->doRunCommand()\n#35 /var/www/html/vendor/symfony/console/Application.php(195): Symfony\\Component\\Console\\Application->doRun()\n#36 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Console/Kernel.php(198): Symfony\\Component\\Console\\Application->run()\n#37 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php(1235): Illuminate\\Foundation\\Console\\Kernel->handle()\n#38 /var/www/html/artisan(13): Illuminate\\Foundation\\Application->handleCommand()\n#39 {main}	2026-03-09 12:49:21
\.


--
-- Data for Name: import_runs; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.import_runs (id, type, file_path, rows_processed, items_created, items_updated, metadata, user_id, started_at, completed_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: inventory_categories; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.inventory_categories (id, name, sort_order, active, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: inventory_items; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.inventory_items (id, category_id, name, sku, par_quantity, active, sort_order, created_at, updated_at, unit_label, unit_multiplier, low_threshold) FROM stdin;
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
50	2026_01_27_180000_add_percent_complete_and_attachments_to_capital_projects	14
51	2026_01_27_000001_add_completed_at_to_todos_table	15
52	2026_01_28_131300_add_updated_at_to_project_updates_table	16
53	2026_01_28_140000_make_apparatus_status_nullable	17
54	2026_01_28_141500_make_all_apparatus_columns_nullable	18
55	2026_01_28_150000_create_unit_master_vehicles_table	19
56	2026_01_28_200000_create_under_25k_projects_table	20
57	2026_01_28_200001_create_under_25k_project_updates_table	20
58	2026_01_29_160000_add_missing_columns_to_under_25k_projects_table	21
59	2026_02_02_000001_add_station_id_to_apparatuses_table	22
60	2026_02_02_000002_add_station_id_to_capital_projects_table	22
61	2026_02_02_000003_add_station_id_to_under_25k_projects_table	22
62	2026_02_02_000004_create_rooms_table	22
63	2026_02_02_000005_create_room_assets_table	22
64	2026_02_02_000006_create_room_audits_table	22
67	2026_02_02_000007_create_room_audit_items_table	23
68	2026_02_02_000008_populate_station_relationships	23
69	2026_02_03_000001_fix_capital_projects_schema	24
70	2026_02_03_000001_create_big_ticket_requests_table	25
71	2026_02_03_000002_create_station_inventory_submissions_table	25
72	2026_02_03_000003_add_fields_to_station_inventory_submissions_table	26
73	2026_02_03_000001_create_single_gas_meters_table	27
74	2026_02_09_000001_create_external_sources_table	28
75	2026_02_09_000002_create_external_nav_items_table	28
76	2026_02_09_000001_create_training_todos_table	29
77	2026_02_09_000003_add_status_priority_to_training_todos_table	29
78	2026_02_10_140700_fix_chatify_uuid_defaults	30
79	2026_02_13_000001_create_inventory_categories_table	30
80	2026_02_13_000002_create_inventory_items_table	30
81	2026_02_13_000003_create_station_inventory_items_table	30
82	2026_02_13_000004_create_station_inventory_audits_table	30
83	2026_02_13_000005_add_unit_fields_to_inventory_items_table	30
84	2026_02_13_000005_create_station_supply_requests_table	30
85	2026_02_13_000006_add_inventory_pin_hash_to_stations_table	30
86	2026_02_14_000001_update_inventory_units_and_counts	30
87	2026_02_14_000002_correct_inventory_units	30
88	2026_02_14_000003_fix_inventory_names_and_values	30
89	2026_02_15_000000_add_low_threshold_to_inventory_items_table	30
90	2026_02_17_000001_add_is_admin_to_users_table	30
91	2026_02_17_000002_add_name_and_is_active_to_stations_table	30
92	2026_02_17_000003_make_stations_zip_code_nullable	30
93	2026_02_17_000004_create_chatify_tables_if_missing	30
94	2026_03_02_000001_create_workgroups_table	31
95	2026_03_02_000002_create_workgroup_members_table	31
96	2026_03_02_000003_create_workgroup_sessions_table	31
97	2026_03_02_000004_create_workgroup_files_table	31
98	2026_03_02_000005_create_workgroup_shared_uploads_table	31
99	2026_03_02_000006_create_workgroup_notes_table	31
100	2026_03_02_000007_create_evaluation_categories_table	31
101	2026_03_02_000008_create_evaluation_templates_table	31
102	2026_03_02_000009_create_evaluation_criteria_table	31
103	2026_03_02_000010_create_candidate_products_table	31
104	2026_03_02_000011_create_evaluation_submissions_table	31
105	2026_03_02_000012_create_evaluation_scores_table	31
106	2026_03_02_000013_create_evaluation_comments_table	31
107	2026_03_02_000020_add_assessment_profile_to_evaluation_categories	32
108	2026_03_02_000021_add_rubric_fields_to_evaluation_submissions	32
109	2026_02_17_000005_update_apparatuses_status_constraint	33
110	2026_03_03_000001_add_reported_at_to_apparatuses	34
111	2026_03_04_000001_create_session_user_pivot_table	35
112	2026_03_04_000002_add_is_locked_and_session_to_evaluation_submissions	35
113	2026_03_04_000003_add_compound_unique_on_evaluation_submissions	35
114	2026_03_04_000004_add_sharing_fields_to_workgroup_notes	36
115	2026_03_04_000005_normalize_user_emails_case_insensitive	37
116	2026_03_06_000001_add_count_evaluations_to_workgroup_members	38
117	2026_03_06_000002_add_plain_password_to_users	39
118	2026_03_07_000001_create_session_workgroup_member_attendance_table	40
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
2	App\\Models\\User	5
1	App\\Models\\User	5
2	App\\Models\\User	7
1	App\\Models\\User	7
5	App\\Models\\User	19
5	App\\Models\\User	20
5	App\\Models\\User	21
5	App\\Models\\User	22
5	App\\Models\\User	23
5	App\\Models\\User	24
5	App\\Models\\User	25
5	App\\Models\\User	26
5	App\\Models\\User	27
1	App\\Models\\User	1
2	App\\Models\\User	1
1	App\\Models\\User	2
2	App\\Models\\User	2
1	App\\Models\\User	3
2	App\\Models\\User	3
1	App\\Models\\User	6
2	App\\Models\\User	6
1	App\\Models\\User	4
2	App\\Models\\User	4
2	App\\Models\\User	11
3	App\\Models\\User	11
2	App\\Models\\User	13
3	App\\Models\\User	13
2	App\\Models\\User	14
3	App\\Models\\User	14
2	App\\Models\\User	12
3	App\\Models\\User	12
5	App\\Models\\User	28
5	App\\Models\\User	29
5	App\\Models\\User	30
5	App\\Models\\User	31
5	App\\Models\\User	32
5	App\\Models\\User	33
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
1d0aa222-e38d-458e-a0f2-979c950e7ec1	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Box Truck", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/2/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 20:53:10	2026-03-03 20:53:10
c80721a5-9891-4d26-81a9-a72eec0820bf	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Loose item equipment", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/1/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:08	2026-03-03 21:14:08
d8c4ca7e-5c8e-4587-89c0-4c4d8ce486fb	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: SCBA maintenance", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/3/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:18	2026-03-03 21:14:18
a236a545-0ca9-4a42-a5b7-721c40841d15	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: ECO Battery Recall", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/4/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:18	2026-03-03 21:14:18
1521a828-a72a-4d53-ac25-8c684aa4e866	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Arrange bunker gear cleaning install", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/5/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:18	2026-03-03 21:14:18
e857c2cc-ec64-4aa2-b35c-5a30844e36e5	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Bunker Gear Drying Equip. Quote", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/6/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:18	2026-03-03 21:14:18
a9d944d3-543e-4abc-b7b3-32423f003dbf	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	1	{"body": "You have been assigned to: SOG Review", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/7/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:18	2026-03-03 21:14:18
345af304-725f-4e49-850e-c79671af1af1	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: SOG Review", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/7/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
5a7febd0-83e1-4327-88d0-2f923c6195da	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Send sensits for repair", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/8/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
28f62c50-76c6-441d-9bf8-13f032a6f649	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Pick up chainsaws from fleet", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/9/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
795aad08-081f-4560-ab43-242a5513eca6	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	1	{"body": "You have been assigned to: Baseball hat design", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/10/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
598a2c04-0f7e-4016-8987-d7af5f2b4815	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	1	{"body": "You have been assigned to: White Polo sizing", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/11/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
45ee0bdd-9107-468e-b6a1-1801c8c7f895	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	6	{"body": "You have been assigned to: White Polo sizing", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/11/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
35c9f19c-9a1a-4fde-962a-2045374ffd15	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: 1:30pm 1/28/26 SafetySuite 2.0 Webinar", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/12/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
f928f1ef-ebc6-476a-aa0c-982f02f90a29	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	1	{"body": "You have been assigned to: 1:30pm 1/28/26 SafetySuite 2.0 Webinar", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/12/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
c99179a1-76c8-4e58-9a3e-fc8ab203752b	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Request quote for single gas CO monitors", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/13/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
f996c4fa-896f-4ec2-873f-a6e258148cbc	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Repair fit testing equipment", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/14/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
eedfe22c-5876-4906-84f6-4d25a5176e8a	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Demo logistics program", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/15/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
6d26c5e7-4ac8-4f0b-8d7e-5b780df9073c	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	1	{"body": "You have been assigned to: Demo logistics program", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/15/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
411e97c4-f50d-401d-892b-df8506a7a3fc	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Find second flashlight box", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/16/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
b244266b-df95-4a9f-99d4-6ec6e99c4b73	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	4	{"body": "You have been assigned to: Ladder truck for Wed", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/17/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
c1206409-4989-407f-a60e-b97e9bdbf25c	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Ladder truck for Wed", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/17/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
a9e03b9c-2ec2-44e1-b0ec-69f33a5f0ea4	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	2	{"body": "You have been assigned to: Update station supply order sheet", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/18/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
c9865ed2-ea05-4903-a923-9a6761c869c1	Filament\\Notifications\\DatabaseNotification	App\\Models\\User	6	{"body": "You have been assigned to: Update station supply order sheet", "icon": "heroicon-o-clipboard-document-list", "view": "filament-notifications::notification", "color": null, "title": "New Todo Assigned", "format": "filament", "status": null, "actions": [{"url": "https://support.darleyplex.com/admin/todos/18/edit", "icon": null, "name": "view", "size": "sm", "view": "filament-actions::link-action", "color": null, "event": null, "label": "View Todo", "tooltip": null, "iconSize": null, "eventData": [], "isDisabled": false, "isOutlined": false, "shouldClose": false, "iconPosition": "before", "extraAttributes": [], "shouldMarkAsRead": true, "dispatchDirection": false, "shouldMarkAsUnread": false, "dispatchToComponent": null, "shouldOpenUrlInNewTab": false}], "duration": "persistent", "viewData": [], "iconColor": "primary"}	\N	2026-03-03 21:14:43	2026-03-03 21:14:43
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
1	view_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
2	view_any_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
3	create_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
4	update_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
5	restore_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
6	restore_any_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
7	replicate_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
8	reorder_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
9	delete_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
10	delete_any_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
11	force_delete_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
12	force_delete_any_apparatus	web	2026-02-09 12:42:21	2026-02-09 12:42:21
13	view_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
14	view_any_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
15	create_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
16	update_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
17	restore_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
18	restore_any_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
19	replicate_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
20	reorder_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
21	delete_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
22	delete_any_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
23	force_delete_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
24	force_delete_any_capital::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
25	view_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
26	view_any_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
27	create_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
28	update_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
29	restore_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
30	restore_any_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
31	replicate_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
32	reorder_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
33	delete_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
34	delete_any_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
35	force_delete_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
36	force_delete_any_defect	web	2026-02-09 12:42:22	2026-02-09 12:42:22
37	view_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
38	view_any_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
39	create_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
40	update_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
41	restore_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
42	restore_any_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
43	replicate_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
44	reorder_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
45	delete_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
46	delete_any_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
47	force_delete_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
48	force_delete_any_equipment::item	web	2026-02-09 12:42:22	2026-02-09 12:42:22
49	view_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
50	view_any_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
51	create_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
52	update_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
53	restore_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
54	restore_any_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
55	replicate_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
56	reorder_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
57	delete_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
58	delete_any_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
59	force_delete_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
60	force_delete_any_inspection	web	2026-02-09 12:42:22	2026-02-09 12:42:22
61	view_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
62	view_any_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
63	create_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
64	update_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
65	restore_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
66	restore_any_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
67	replicate_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
68	reorder_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
69	delete_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
70	delete_any_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
71	force_delete_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
72	force_delete_any_inventory::location	web	2026-02-09 12:42:22	2026-02-09 12:42:22
73	view_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
74	view_any_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
75	create_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
76	update_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
77	restore_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
78	restore_any_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
79	replicate_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
80	reorder_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
81	delete_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
82	delete_any_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
83	force_delete_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
84	force_delete_any_recommendation	web	2026-02-09 12:42:22	2026-02-09 12:42:22
85	view_role	web	2026-02-09 12:42:22	2026-02-09 12:42:22
86	view_any_role	web	2026-02-09 12:42:22	2026-02-09 12:42:22
87	create_role	web	2026-02-09 12:42:22	2026-02-09 12:42:22
88	update_role	web	2026-02-09 12:42:22	2026-02-09 12:42:22
89	delete_role	web	2026-02-09 12:42:22	2026-02-09 12:42:22
90	delete_any_role	web	2026-02-09 12:42:22	2026-02-09 12:42:22
91	view_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
92	view_any_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
93	create_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
94	update_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
95	restore_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
96	restore_any_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
97	replicate_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
98	reorder_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
99	delete_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
100	delete_any_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
101	force_delete_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
102	force_delete_any_shop::work	web	2026-02-09 12:42:22	2026-02-09 12:42:22
103	view_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
104	view_any_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
105	create_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
106	update_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
107	restore_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
108	restore_any_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
109	replicate_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
110	reorder_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
111	delete_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
112	delete_any_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
113	force_delete_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
114	force_delete_any_single::gas::meter	web	2026-02-09 12:42:22	2026-02-09 12:42:22
115	view_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
116	view_any_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
117	create_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
118	update_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
119	restore_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
120	restore_any_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
121	replicate_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
122	reorder_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
123	delete_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
124	delete_any_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
125	force_delete_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
126	force_delete_any_station	web	2026-02-09 12:42:22	2026-02-09 12:42:22
127	view_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
128	view_any_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
129	create_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
130	update_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
131	restore_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
132	restore_any_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
133	replicate_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
134	reorder_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
135	delete_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
136	delete_any_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
137	force_delete_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
138	force_delete_any_station::inventory::submission	web	2026-02-09 12:42:22	2026-02-09 12:42:22
139	view_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
140	view_any_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
141	create_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
142	update_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
143	restore_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
144	restore_any_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
145	replicate_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
146	reorder_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
147	delete_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
148	delete_any_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
149	force_delete_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
150	force_delete_any_todo	web	2026-02-09 12:42:22	2026-02-09 12:42:22
151	view_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
152	view_any_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
153	create_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
154	update_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
155	restore_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
156	restore_any_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
157	replicate_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
158	reorder_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
159	delete_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
160	delete_any_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
161	force_delete_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
162	force_delete_any_under25k::project	web	2026-02-09 12:42:22	2026-02-09 12:42:22
163	view_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
164	view_any_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
165	create_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
166	update_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
167	restore_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
168	restore_any_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
169	replicate_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
170	reorder_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
171	delete_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
172	delete_any_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
173	force_delete_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
174	force_delete_any_uniform	web	2026-02-09 12:42:22	2026-02-09 12:42:22
175	view_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
176	view_any_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
177	create_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
178	update_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
179	restore_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
180	restore_any_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
181	replicate_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
182	reorder_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
183	delete_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
184	delete_any_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
185	force_delete_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
186	force_delete_any_unit::master::vehicle	web	2026-02-09 12:42:22	2026-02-09 12:42:22
187	view_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
188	view_any_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
189	create_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
190	update_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
191	restore_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
192	restore_any_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
193	replicate_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
194	reorder_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
195	delete_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
196	delete_any_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
197	force_delete_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
198	force_delete_any_user	web	2026-02-09 12:42:22	2026-02-09 12:42:22
199	page_MyProfile	web	2026-02-09 12:42:22	2026-02-09 12:42:22
200	page_Settings	web	2026-02-09 12:42:22	2026-02-09 12:42:22
201	widget_FleetStatsWidget	web	2026-02-09 12:42:22	2026-02-09 12:42:22
202	widget_InventoryOverviewWidget	web	2026-02-09 12:42:23	2026-02-09 12:42:23
203	widget_TodoOverviewWidget	web	2026-02-09 12:42:23	2026-02-09 12:42:23
204	widget_SmartUpdatesWidget	web	2026-02-09 12:42:23	2026-02-09 12:42:23
205	training.access	web	2026-02-09 12:42:33	2026-02-09 12:42:33
206	training.manage_external_links	web	2026-02-09 12:42:33	2026-02-09 12:42:33
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
49	42	Design Phase Complete	Architectural and engineering designs finalized and approved.	2026-05-01	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
50	42	Permits Obtained	All necessary building permits and approvals secured.	2026-05-01	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
51	42	Construction Started	Construction phase begins with contractor mobilization.	2026-06-01	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
52	42	Final Inspection	Final walkthrough and inspection completed, punch list items addressed.	2026-10-01	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
53	43	Design Phase Complete	Architectural and engineering designs finalized and approved.	2026-04-19	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
54	43	Permits Obtained	All necessary building permits and approvals secured.	2026-05-19	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
55	43	Construction Started	Construction phase begins with contractor mobilization.	2026-06-19	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
56	43	Final Inspection	Final walkthrough and inspection completed, punch list items addressed.	2026-10-19	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
57	44	Design Phase Complete	Architectural and engineering designs finalized and approved.	2026-03-29	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
58	44	Permits Obtained	All necessary building permits and approvals secured.	2026-05-29	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
59	44	Construction Started	Construction phase begins with contractor mobilization.	2026-06-29	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
60	44	Final Inspection	Final walkthrough and inspection completed, punch list items addressed.	2026-12-29	f	\N	2026-01-29 19:28:09	2026-01-29 19:28:09
\.


--
-- Data for Name: project_updates; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.project_updates (id, capital_project_id, user_id, update_text, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: push_subscriptions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.push_subscriptions (id, subscribable_type, subscribable_id, endpoint, public_key, auth_token, content_encoding, created_at, updated_at) FROM stdin;
1	App\\Models\\User	3	https://web.push.apple.com/QGeGHd9585Zw9lD_Olb1tnd8jHe-ZjaALoM8bDSus3p_inBCgOdFxjOPAsZcxPZLvIaVc0qRzHFDp-s4TsQNrOUaG91fDUdJW08ngc0-pGEu1OhNaPQtTgewdNh4u4sGD-nP4jfh_mCroDKU_TVziTBLQyLioP6p6bVhwWhlKO0	BFp5Svws6yO8edFJ6Cpp70gedtRrD7VJTS7kJehetwUEh0PfFuWXfcJAhleeJ4rSMeFcr-8HIyhWQupqeMEf4Xo	AOSzZPLdYWfmYv4a5ov7GA	\N	2026-01-27 09:21:30	2026-01-27 09:21:30
2	App\\Models\\User	2	https://web.push.apple.com/QDVmwKMeonLVeSs0CRwSOLg-yJbE10jDveglq7aZgC9GaSgJ6suwKUsVgp-eacNdo7W_d1kjO6hhgK4JdkNQ4U21_BWyyIzRrjTrzH4OA0uxuhTMw6RGnC_48kPXH1Npu4Y07up1UbjJAirh0UxvGGQwRW9shxjxqaLR2neIMFE	BJ2hueMtWTaK1Uo1Cl9aTrVHzxdU-MZM3odqIuD83vpkxnuWrXwJd2oENn_0Sobo94xeOvvZlN1AT8yj4Juu7ME	_PiwxFoyWCSeKGZmm93whQ	\N	2026-01-27 13:34:16	2026-01-27 13:34:16
3	App\\Models\\User	3	https://web.push.apple.com/QHKFedZjTC-UnurKmAKqTD0TS9I1zgZZyyQ3QwpuQ7m0ZRSOc-7l1R_lRwCFhaOsAWqrg4IfLkIg2Ekl3owswbUTPGA4DPt42QHAIcbPee4FhahqBTKjJZgy3RxbdrXo8xrYrpn8f8lzkgjSMQw7o01G17pYCmk1i6bYhVL_D-U	BAXX-xSglcJ53vNL4_iyTQLdwxvOaW8nXcDXvQdxmmaRenY0cBlU88YhI9amIHUVuxo71zZjd84PleZTquqe8gs	rfmfpsgXul7Vfup47Tw50A	\N	2026-01-28 12:58:47	2026-01-28 12:58:47
4	App\\Models\\User	2	https://web.push.apple.com/QCscjrjLY7oVuxjnNK7fT6VQCXuf_1CJt8m0YCvJOcajSNkBA4MhENdzRo3khE6NxNDpZNHtVY4xxqBfJUAGxzXs4o5KoE6HHxbqOlxldOIOTue-mb0sQqvMeq4fkB-e6Nw32SmCZ9VPcqMj5eTMtnCJQbpKx2_uSXdKjhDy1Bw	BDyhpbtjttgApHHojivaMSUdsnPx_ROndLMA8BlQHVNc0dRtkP84tyh3ZPxqaWELnjoHi-dCwGQXxbapNKNWsB0	5ki55OpYzgodH-s-Yptclw	\N	2026-01-28 14:03:17	2026-01-28 14:03:17
5	App\\Models\\User	3	https://updates.push.services.mozilla.com/wpush/v2/gAAAAABpgmnlU4GQ6YFDAu7EwLrQEpCHNPC17Qv2xjsCHOFcl6DosREKQy82PH_60mrGFd4hOQ1MZOSMn2FX3dns2UMguzPIUHji8HgxGlOcIrBHJleeWJNfeBbLlalAzYkq1y7RXkULzlLSSlZZG7gMYCcDoCVcg3W7EYbSI1jAQ32w4wCo8ww	BMDM9N_UObvkPcW_gNYL0_gXCzICz9Sp5laAp1CaL0xhTsVDoxFtLBqk_vlTOd49V0hSh7epn_4Cj0zsfQ_wQYg	-uxlMsJin6m0JsbEzHXIrA	\N	2026-02-03 21:34:29	2026-02-03 21:34:29
6	App\\Models\\User	1	https://wns2-ch1p.notify.windows.com/w/?token=BQYAAADMCag5WF1M0savdmirOJLGaqfWkNyhPU4bMWHEMhWCEgqeQfHpdRji7VL8gHIpPd%2bzOi6Cz6R9jY4lE8sZnvw0nor28lkdcaVKb50HakJ9dWaFL%2bLymVDYUqyGI3tq5XtMR3RNlmbi%2b%2fFYgeInHgzAMnpVkbVzE3kkKhZ%2b8orj9bqba2%2b5P9R087wMsylzwtb%2f4qdEJ4M3QJH2sD7CKpglS3kMTae5qRljU72tBOVsetnjyZ8%2fRLDiiofTrv3DSRX0I%2fCReP3R8uOVoro%2b7%2fCxyp%2fQdpNPe0e6QuSWuJYXs%2bFgi3mJiwh0TJa8TA5SfrWcVRGX3vAi5A2qfKAx5oMj	BFmvDi6MhdMRUIeIDc8YPfsS8pyGhyVm4Nd_HN5zNxAd5xOQdWGFyDyp5HQMGoyGv4cbGowKqZM3fBjfSLV1hUE	yHTPoyzS5aEadmR_9Unp_Q	\N	2026-02-03 21:43:13	2026-02-03 21:43:13
7	App\\Models\\User	17	https://updates.push.services.mozilla.com/wpush/v2/gAAAAABppX4hzemTwQc5p6WoY_13dxMXNcpVIw3b5U3r7zzJrzB5XjftlAhXFRj1UV6vkP_Ml0lLFm4o0RC_icMu87OFNC6Qf0VWEtTN43m0PFueyderMQ2qaWAaQHZB4TwJk_Y4mTRtIvDT_mzCjs_uNqeFMtgnCsP1dS-lNTlSJCeiHeupqPc	BCYQd1K-J2r_fdUdtqZpu8td7vsiFe4QfMMC1d30SXUhBCYY-nguG4MrTZT3P7ZYqNMB71__pSraDIoFxVvxgsA	iHBiMOMDwZfXNTCT8B3Aug	\N	2026-03-02 12:10:09	2026-03-02 12:10:09
8	App\\Models\\User	17	https://web.push.apple.com/QBjqYD63tanO2LpQs7SqxymWSDtirHTmGorM4J3Hc5yNwuVnUaxbdg7nbNt8QUxDrLJmnV_Ra4bHsAu7gLE0yv7Guzx26BcvrsI4mJCTt_W-yS4bFv93LpC-oBSHjkhzZrg_A7i39U8gnRD-xcYIsVZaneI5E_waZwi8695vlYE	BNi7hxPSOj9g1X70hEsW78HajzLg9k7cAFoBy3cVIPUH2tZPZOnQ5lVvckvS0ZZCZz_ngHcb5QFHaVKjF1BrO3w	FLahHLcs8JefzqrR35LrKw	\N	2026-03-03 11:17:43	2026-03-03 11:17:43
\.


--
-- Data for Name: role_has_permissions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.role_has_permissions (permission_id, role_id) FROM stdin;
205	3
206	3
205	4
1	1
2	1
3	1
4	1
5	1
6	1
7	1
8	1
9	1
10	1
11	1
12	1
13	1
14	1
15	1
16	1
17	1
18	1
19	1
20	1
21	1
22	1
23	1
24	1
25	1
26	1
27	1
28	1
29	1
30	1
31	1
32	1
33	1
34	1
35	1
36	1
37	1
38	1
39	1
40	1
41	1
42	1
43	1
44	1
45	1
46	1
47	1
48	1
49	1
50	1
51	1
52	1
53	1
54	1
55	1
56	1
57	1
58	1
59	1
60	1
61	1
62	1
63	1
64	1
65	1
66	1
67	1
68	1
69	1
70	1
71	1
72	1
73	1
74	1
75	1
76	1
77	1
78	1
79	1
80	1
81	1
82	1
83	1
84	1
85	1
86	1
87	1
88	1
89	1
90	1
91	1
92	1
93	1
94	1
95	1
96	1
97	1
98	1
99	1
100	1
101	1
102	1
103	1
104	1
105	1
106	1
107	1
108	1
109	1
110	1
111	1
112	1
113	1
114	1
115	1
116	1
117	1
118	1
119	1
120	1
121	1
122	1
123	1
124	1
125	1
126	1
127	1
128	1
129	1
130	1
131	1
132	1
133	1
134	1
135	1
136	1
137	1
138	1
139	1
140	1
141	1
142	1
143	1
144	1
145	1
146	1
147	1
148	1
149	1
150	1
151	1
152	1
153	1
154	1
155	1
156	1
157	1
158	1
159	1
160	1
161	1
162	1
163	1
164	1
165	1
166	1
167	1
168	1
169	1
170	1
171	1
172	1
173	1
174	1
175	1
176	1
177	1
178	1
179	1
180	1
181	1
182	1
183	1
184	1
185	1
186	1
187	1
188	1
189	1
190	1
191	1
192	1
193	1
194	1
195	1
196	1
197	1
198	1
199	1
200	1
201	1
202	1
203	1
204	1
205	1
206	1
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.roles (id, name, guard_name, created_at, updated_at) FROM stdin;
1	super_admin	web	2026-02-09 12:42:21	2026-02-09 12:42:21
2	admin	web	2026-02-09 12:42:33	2026-02-09 12:42:33
3	training_admin	web	2026-02-09 12:42:33	2026-02-09 12:42:33
4	training_viewer	web	2026-02-09 12:42:33	2026-02-09 12:42:33
5	workgroup_member	web	2026-03-02 12:44:58	2026-03-02 12:44:58
6	workgroup_facilitator	web	2026-03-02 12:44:58	2026-03-02 12:44:58
7	workgroup_admin	web	2026-03-02 12:44:58	2026-03-02 12:44:58
8	logistics_admin	web	2026-03-06 23:40:56	2026-03-06 23:40:56
\.


--
-- Data for Name: room_assets; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.room_assets (id, room_id, name, description, quantity, category, condition, serial_number, purchase_date, purchase_price, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: room_audit_items; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.room_audit_items (id, room_audit_id, room_asset_id, status, notes, photos, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: room_audits; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.room_audits (id, room_id, user_id, audit_date, status, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: rooms; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.rooms (id, station_id, name, type, capacity, floor, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: session_user; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public."session_user" (id, workgroup_session_id, user_id, is_official_evaluator, created_at, updated_at) FROM stdin;
8	1	19	t	2026-03-04 02:14:40	2026-03-04 02:14:40
9	1	21	t	2026-03-04 02:14:40	2026-03-04 02:14:40
10	1	6	t	2026-03-04 02:14:40	2026-03-04 02:14:40
11	1	3	t	2026-03-04 02:14:40	2026-03-04 02:14:40
12	1	2	t	2026-03-04 02:14:40	2026-03-04 02:14:40
\.


--
-- Data for Name: session_workgroup_member_attendance; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.session_workgroup_member_attendance (id, workgroup_session_id, workgroup_member_id, created_at, updated_at) FROM stdin;
2	1	16	2026-03-07 23:21:02	2026-03-07 23:21:02
4	1	4	2026-03-07 23:21:02	2026-03-07 23:21:02
5	1	14	2026-03-07 23:33:15	2026-03-07 23:33:15
6	1	13	2026-03-07 23:33:15	2026-03-07 23:33:15
7	1	10	2026-03-07 23:33:15	2026-03-07 23:33:15
8	4	16	2026-03-08 10:47:52	2026-03-08 10:47:52
9	4	19	2026-03-08 10:47:52	2026-03-08 10:47:52
10	4	20	2026-03-08 10:47:52	2026-03-08 10:47:52
11	4	13	2026-03-08 10:47:52	2026-03-08 10:47:52
12	4	23	2026-03-08 10:47:52	2026-03-08 10:47:52
13	4	24	2026-03-08 10:47:52	2026-03-08 10:47:52
14	3	2	2026-03-08 11:00:31	2026-03-08 11:00:31
15	3	14	2026-03-08 11:00:31	2026-03-08 11:00:31
16	3	16	2026-03-08 11:00:31	2026-03-08 11:00:31
17	3	22	2026-03-08 11:00:31	2026-03-08 11:00:31
18	3	24	2026-03-08 11:00:31	2026-03-08 11:00:31
19	3	4	2026-03-08 11:00:31	2026-03-08 11:00:31
20	3	23	2026-03-08 11:00:31	2026-03-08 11:00:31
21	3	10	2026-03-08 11:00:31	2026-03-08 11:00:31
22	3	13	2026-03-08 11:00:31	2026-03-08 11:00:31
23	3	20	2026-03-08 11:00:31	2026-03-08 11:00:31
24	3	21	2026-03-08 11:00:31	2026-03-08 11:00:31
25	3	19	2026-03-08 11:00:31	2026-03-08 11:00:31
26	1	7	2026-03-08 11:00:59	2026-03-08 11:00:59
27	1	6	2026-03-08 11:00:59	2026-03-08 11:00:59
28	4	7	2026-03-08 11:01:11	2026-03-08 11:01:11
29	4	6	2026-03-08 11:01:11	2026-03-08 11:01:11
30	4	4	2026-03-08 16:49:05	2026-03-08 16:49:05
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
j6KBJMi5pnQAFmjsl8VpBuEBcWZW4bc80yleu6Fe	\N	43.157.168.43	Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.3 Mobile/15E148 Safari/604.1	YTozOntzOjY6Il90b2tlbiI7czo0MDoiY044Yk52Ylo4UjFIRzFld3J4R1Nrb1Zsb3RsZVc0N1duenRQQkVUVCI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MTg6Imh0dHA6Ly9tYmZkaHViLmNvbSI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1773058456
4r2rkDuuK9LGYoCO41EMKMkOf4yXEL7vaS7AprqA	\N	52.173.163.42	Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Mobile Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiY0h4cklHb01LajExVkliZHBMTGRMOTBPVnhTTFlVVndEaUREOTdRVCI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MzA6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbSI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1773054670
a5Ib90feuIwlLM2wmJmtqjC6cAWmmo1SuK0qMytd	\N	64.236.161.35	Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Mobile Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiakw4S05BZTEwc1lxZURjWXZCR2N4S0FZUHFwOVh5V09RZXFxNW9iZiI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MzA6Imh0dHBzOi8vc3VwcG9ydC5kYXJsZXlwbGV4LmNvbSI7fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=	1773060593
L4PKKQa580GuyTOruLpIKSaFw4uNotKIMPaOWYBb	\N	43.130.228.73	Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.3 Mobile/15E148 Safari/604.1	YTozOntzOjY6Il90b2tlbiI7czo0MDoiTEw5ZnVZOW8zMVRzNzJCSUNCbG55YzFtdWdIY1hnODFiOWZvZFh6eSI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MjI6Imh0dHA6Ly93d3cubWJmZGh1Yi5jb20iO31zOjY6Il9mbGFzaCI7YToyOntzOjM6Im9sZCI7YTowOnt9czozOiJuZXciO2E6MDp7fX19	1773058632
CSyp5nDIjbPhGajT9LRxK2FwYvqXixq21lSEtUr1	\N	64.236.161.35	Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Mobile Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiRHJBWVNOaWN4ejZoMkJoSEhnUmk2SEtBNXBjTnVocVIyQWZkakNhbSI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MjM6Imh0dHBzOi8vd3d3Lm1iZmRodWIuY29tIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1773060606
eXjLSGw7AJBiozrN0wPOYsik3saLHh3aceqdtOOW	\N	51.8.152.234	curl/8.5.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoiOVp5bnFiV1VRS1JPdFlxV0lzRkFhSUVtVUFXTWsyTmZ4VTlWQ0tKeCI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6Mjk6Imh0dHBzOi8vbWJmZGh1Yi5jb20vX192ZXJzaW9uIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1773054675
6IBixZPubYVJi1pdx1dFcwq2MDwHpDlKoXn9i2Sw	\N	172.178.119.114	curl/8.5.0	YTozOntzOjY6Il90b2tlbiI7czo0MDoieTF1Unp1YkRtSDhISXhSbXgwdDVjVkxhY1l1ZlQ2MWFtOW9QT244OSI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6Mjk6Imh0dHBzOi8vbWJmZGh1Yi5jb20vX192ZXJzaW9uIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1773060619
gjtwsiuWsvQjmh8c4rPaQobg2AmgTKVkEduVmK0J	\N	52.173.163.42	Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Mobile Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiZzlOVFZRSWhtZ2ZZOHdEcHpkRUxkZkNyZGZMNXl1aEpHQXVIQ2FvTyI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MjM6Imh0dHBzOi8vd3d3Lm1iZmRodWIuY29tIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1773054684
IZ3J04MCAbcr1roOg0NB3E5X6q2O8frAQO6y9STd	\N	8.30.243.146	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	YTozOntzOjY6Il90b2tlbiI7czo0MDoiVkxmR2N3ZXJFMmVkck9NMTFiZHNSUUF3anI2ckFVc1g3WW13VlQ0WiI7czo5OiJfcHJldmlvdXMiO2E6MTp7czozOiJ1cmwiO3M6MzU6Imh0dHBzOi8vd3d3Lm1iZmRodWIuY29tL2FkbWluL2xvZ2luIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==	1773056641
oICOMpqEVEkW2MuxtTcec8JQxcW137cItHkrLGzs	3	8.30.243.146	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	YTo4OntzOjY6Il90b2tlbiI7czo0MDoiOURwd0MxVkpIWHVOQ0RNRlFYSHdTVFVQNHdDQk9oRnpuazdhazJRdyI7czozOiJ1cmwiO2E6MDp7fXM6OToiX3ByZXZpb3VzIjthOjE6e3M6MzoidXJsIjtzOjQwOiJodHRwczovL3d3dy5tYmZkaHViLmNvbS93b3JrZ3JvdXBzL25vdGVzIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319czo1MDoibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MztzOjE3OiJwYXNzd29yZF9oYXNoX3dlYiI7czo2MDoiJDJ5JDEyJDZpdWNtTG5OQnltSVgyMVFhNlFZVE90NnBPVGxFZWlGWXZhVTN6Y1RTVXVNMmZ4S2RaaEE2IjtzOjY6InRhYmxlcyI7YToxOntzOjQxOiJlYmVkYTA4NjZlOTZhNzJjMmMzNTQxNTVmNmI0Y2I4Nl9wZXJfcGFnZSI7czozOiJhbGwiO31zOjg6ImZpbGFtZW50IjthOjA6e319	1773060451
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
-- Data for Name: single_gas_meters; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.single_gas_meters (id, apparatus_id, serial_number, activation_date, expiration_date, created_at, updated_at) FROM stdin;
1	40	CO928	2026-02-03	2028-02-03	2026-02-03 15:09:36	2026-02-03 15:09:36
2	39	CO422	2026-02-03	2028-02-03	2026-02-03 15:09:55	2026-02-03 15:09:55
3	38	CO198	2026-02-03	2028-02-03	2026-02-03 15:10:18	2026-02-03 15:10:18
4	44	CO180	2026-02-03	2028-02-03	2026-02-03 15:10:34	2026-02-03 15:10:34
5	41	CO405	2026-02-03	2028-02-03	2026-02-03 15:16:05	2026-02-03 15:16:05
6	45	CO424	2026-02-03	2028-02-03	2026-02-03 15:52:09	2026-02-03 15:52:09
7	46	CO425	2026-02-03	2028-02-03	2026-02-03 15:52:23	2026-02-03 15:52:23
8	47	CO184	2026-02-03	2028-02-03	2026-02-03 15:52:52	2026-02-03 15:53:29
9	48	CO197	2026-02-03	2028-02-03	2026-02-03 15:53:52	2026-02-03 15:54:05
10	49	CO176	2026-02-03	2028-02-03	2026-02-03 15:54:31	2026-02-03 15:54:31
11	50	CO188	2026-02-03	2028-02-03	2026-02-03 15:54:49	2026-02-03 15:54:49
12	51	CO411	2026-02-03	2028-02-03	2026-02-03 15:55:06	2026-02-03 15:55:06
13	52	CO423	2026-02-03	2028-02-03	2026-02-03 15:55:21	2026-02-03 15:55:21
\.


--
-- Data for Name: station_inventory_audits; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.station_inventory_audits (id, station_id, inventory_item_id, actor_name, actor_shift, action, from_value, to_value, created_at) FROM stdin;
\.


--
-- Data for Name: station_inventory_items; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.station_inventory_items (id, station_id, inventory_item_id, on_hand, status, last_updated_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: station_inventory_submissions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.station_inventory_submissions (id, station_id, items, pdf_path, created_by, created_at, updated_at, employee_name, shift, notes, submitted_at) FROM stdin;
1	29	[{"id":"kitchen_liner","name":"Kitchen Liner (13 gal)","quantity":2,"category":"garbage_paper"}]	inventory-submissions/inventory-29-1770141566.pdf	1	2026-02-03 17:59:26	2026-02-03 17:59:26	Test User	A	PDF generation test	2026-02-03 12:59:26
2	29	[{"category_id":"garbage_paper","item_id":"REN22505-CA","quantity":1},{"category_id":"kitchen","item_id":"REN02118","quantity":1},{"category_id":"kitchen","item_id":"REN05001-AM","quantity":1}]	inventory-submissions/inventory-29-1770221996.pdf	1	2026-02-04 16:19:56	2026-02-04 16:19:56	Peter	A	\N	2026-02-04 11:19:56
\.


--
-- Data for Name: station_supply_requests; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.station_supply_requests (id, station_id, request_text, status, created_by_name, created_by_shift, admin_notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: stations; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.stations (id, station_number, address, city, state, zip_code, captain_in_charge, phone, notes, created_at, updated_at, inventory_pin_hash, name, is_active) FROM stdin;
29	1	1051 Jefferson Ave.	Miami Beach	FL	33139	\N	305.673.7135	\N	2026-01-25 18:20:01	2026-01-26 01:16:09	\N	\N	t
30	2	2300 Pine Tree Dr.	Miami Beach	FL	33140	\N	305.673.7171	\N	2026-01-25 18:20:01	2026-01-26 01:16:09	\N	\N	t
31	3	5303 Collins Ave.	Miami Beach	FL	33140	\N	305.673.7179	\N	2026-01-25 18:20:01	2026-01-26 01:16:09	\N	\N	t
32	4	6880 Indian Creek Dr.	Miami Beach	FL	33141	\N	305.673.7136	\N	2026-01-25 18:20:01	2026-01-26 01:16:09	\N	\N	t
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
4	7	3	Peter Darley	Rewrite edits from Tuesdays meeting. 	2026-01-27 16:20:51	2026-01-27 16:20:51
5	13	3	Peter Darley	Contacted Scot and requested a quote.	2026-01-28 17:58:24	2026-01-28 17:58:24
7	8	3	Peter Darley	219-465-2700 ext. 247 Nick for troubleshooting sensits. 	2026-02-02 14:01:19	2026-02-02 14:01:19
8	16	3	Peter Darley	Called John to track down additional missing boxes. 	2026-02-02 14:04:37	2026-02-02 14:04:37
9	4	3	Peter Darley	Shipped out. 	2026-02-02 18:07:23	2026-02-02 18:07:23
10	8	3	Peter Darley	One Sensit repaired in house.  One sent out for repair.  	2026-02-02 18:07:51	2026-02-02 18:07:51
11	16	1	Miguel Anchia	Found by Chief Anchia since no one else could find it	2026-02-04 18:10:27	2026-02-04 18:10:27
\.


--
-- Data for Name: todos; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.todos (id, sort, created_at, updated_at, title, description, is_completed, assigned_to_user_id, due_at, assigned_to, created_by, attachments, status, priority, assigned_by, completed_at, created_by_user_id) FROM stdin;
1	0	2026-01-26 08:58:21	2026-01-26 10:14:39	Loose item equipment	<p>Review new ladder loose item equipment list.</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
4	0	2026-01-26 12:30:48	2026-01-26 12:30:48	ECO Battery Recall	<p>Install firmware update on the ECO BATT Generator.&nbsp;&nbsp;</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
5	0	2026-01-26 12:31:51	2026-01-26 12:31:51	Arrange bunker gear cleaning install	<p>Get Gressia to contact company and install the bunger gear cleaning equipment.</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
6	0	2026-01-26 12:32:59	2026-01-26 12:32:59	Bunker Gear Drying Equip. Quote	<p>Company contacted for quote request on bunker gear cleaning for 4 person ambient air system with size specs.&nbsp;&nbsp;</p>	f	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
10	0	2026-01-26 22:30:28	2026-01-26 22:30:28	Baseball hat design	<p>Seek approval of final design to submit to All Uniforms</p>	f	\N	\N	["1","3","2"]	2	[]	pending	medium	\N	\N	\N
11	0	2026-01-26 22:32:23	2026-01-26 22:32:23	White Polo sizing	<p>Size command staff for white polo (with Elbeco sizing kit).&nbsp;</p><p>Submit list to All Uniforms</p>	f	\N	\N	["6","3","1","2"]	2	[]	pending	medium	\N	\N	\N
9	0	2026-01-26 19:02:29	2026-01-27 19:34:13	Pick up chainsaws from fleet	<p>Pick up chainsaws from fleet on drive in.</p>	t	\N	\N	["3","2"]	3	[]	pending	medium	\N	\N	\N
2	0	2026-01-26 09:29:02	2026-01-28 11:57:07	Box Truck	<p>Review box truck plans / build</p>	f	\N	\N	["3","2"]	3	["todo-attachments\\/01KG27DSGNT37A7XWXEDH8ZVGR.jpg","todo-attachments\\/01KG27DSGQ4JSVMS7W4HEF3X5E.jpg","todo-attachments\\/01KG27DSGRJQWX0T0CWG850WH9.jpeg","todo-attachments\\/01KG27DSGTH1J31XV2D9Q02KBH.png","todo-attachments\\/01KG27DSH7WWS3AD0HNAATZ83X.jpg","todo-attachments\\/01KG27DSH7WWS3AD0HNAATZ83Y.jpeg","todo-attachments\\/01KG27DSHBQDMG8PFHTGEZ918Z.jpeg","todo-attachments\\/01KG27DSHFK8XWE7QX1R71HHG7.jpeg","todo-attachments\\/01KG27DSHKQK4CHEGVXNKFWAXR.jpeg","todo-attachments\\/01KG27DSHR0946B7SRRN86G51G.jpeg","todo-attachments\\/01KG27DSHXN3NM5SRJSM5EM7NJ.jpeg","todo-attachments\\/01KG27DSJ1M4KMA5VZSR19G4V6.jpeg","todo-attachments\\/01KG27DSJ5VGY1AA64VYJMF05J.jpeg","todo-attachments\\/01KG27DSJ8FSQ127DT5KETFTMM.jpeg","todo-attachments\\/01KG27DSJDMQ768KECPM1QWXBS.jpeg"]	pending	medium	\N	\N	\N
12	0	2026-01-28 15:00:32	2026-01-28 15:00:32	1:30pm 1/28/26 SafetySuite 2.0 Webinar	<p>The calibration program that manages our Honeywell gas monitors (BW / MultiRae) is changing.<br><br>We need to attend.&nbsp;&nbsp;<br><br>https://gcc02.safelinks.protection.outlook.com/?url=http%3A%2F%2Fgosps.honeywell.com%2FNTEwLVJFSS0yMTkAAAGfVqbel4u66114Vcte9FKgJx7gW_xVAh0zW5nB_BmiLu1GSaOOnL0Jt0NqSRSHWZA1h8yp284%3D&amp;data=05%7C02%7CPeterDarley%40miamibeachfl.gov%7C6eeb48800bf0456c6d2708de52aea018%7C551608f948f34871808aa969ec5cf48a%7C0%7C0%7C639039107126410695%7CUnknown%7CTWFpbGZsb3d8eyJFbXB0eU1hcGkiOnRydWUsIlYiOiIwLjAuMDAwMCIsIlAiOiJXaW4zMiIsIkFOIjoiTWFpbCIsIldUIjoyfQ%3D%3D%7C0%7C%7C%7C&amp;sdata=MOW%2Beh3o%2Ft14m2W%2Bk2t1WXXEyrSUOigBrEDY1rylQRg%3D&amp;reserved=0</p>	f	\N	\N	["2","1","3"]	3	[]	pending	medium	\N	\N	\N
3	0	2026-01-26 12:28:21	2026-01-28 15:25:13	SCBA maintenance	<p>Paul Rogers coming today for SCBA maintenance.&nbsp;</p>	t	\N	\N	["3","2"]	3	[]	pending	medium	\N	2026-01-28 15:25:13	\N
14	0	2026-01-28 17:59:01	2026-01-28 17:59:01	Repair fit testing equipment	<p>Contact company regarding fit testing machine.</p>	f	\N	\N	["2"]	3	[]	pending	medium	\N	\N	\N
15	0	2026-01-29 15:07:18	2026-01-29 15:07:18	Demo logistics program	<p>Contact operativeIQ for logistics/ truck check out equipment.&nbsp;</p>	f	\N	\N	["2","1","3"]	3	[]	pending	medium	\N	\N	\N
13	0	2026-01-28 17:58:07	2026-02-01 15:19:39	Request quote for single gas CO monitors	<p>Request quote to replace the single gas CO monitors on the rescue LifePaks.</p>	t	\N	\N	["2","3"]	3	[]	pending	medium	\N	2026-02-01 15:19:39	\N
7	0	2026-01-26 13:42:25	2026-02-01 15:19:58	SOG Review	<p>Review Support SOGs and provide update / feedback to Chief Mestas.&nbsp;</p>	t	\N	\N	["1","3","2"]	3	["todo-attachments\\/01KFX8N6C6KBMMKZGAZT1V1J1X.pdf"]	pending	medium	\N	2026-02-01 15:19:58	\N
8	0	2026-01-26 19:00:00	2026-02-03 16:42:25	Send sensits for repair	<p>Send sensits off to ten-8 or Scott Richardson for repair.&nbsp; Or consider replacing with new ones.</p>	t	\N	\N	["3","2"]	3	[]	pending	medium	\N	2026-02-03 16:42:25	\N
18	0	2026-02-04 16:27:54	2026-02-04 16:27:54	Update station supply order sheet	<p>Update supply sheet with Granger products...</p>	f	\N	\N	["6","2","3"]	3	[]	pending	medium	\N	\N	\N
16	0	2026-01-29 15:08:27	2026-02-04 18:11:42	Find second flashlight box	<p>Track down second flashlight box.&nbsp;</p>	t	\N	\N	["2","3"]	3	[]	pending	medium	\N	2026-02-04 18:11:42	\N
17	0	2026-02-02 19:02:44	2026-02-05 15:00:39	Ladder truck for Wed	<p>Training requesting ladder truck for state of the city address on Wednesday.&nbsp;</p>	t	\N	\N	["2","3","4"]	3	[]	pending	medium	\N	2026-02-05 15:00:39	\N
\.


--
-- Data for Name: training_todo_updates; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.training_todo_updates (id, training_todo_id, user_id, username, comment, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: training_todos; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.training_todos (id, title, description, is_completed, sort, assigned_to, created_by, completed_at, attachments, created_at, updated_at, status, priority) FROM stdin;
\.


--
-- Data for Name: under_25k_project_updates; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.under_25k_project_updates (id, under_25k_project_id, user_id, title, body, percent_complete_snapshot, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: under_25k_projects; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.under_25k_projects (id, project_number, name, description, budget_amount, spend_amount, status, priority, start_date, target_completion_date, actual_completion_date, project_manager, notes, percent_complete, internal_notes, attachments, attachment_file_names, created_at, updated_at, zone, miami_beach_area, munis_adopted_amended, munis_transfers_in_out, munis_revised_budget, internal_transfers_in_out, internal_revised_budget, requisitions, actual_expenses, project_balance_savings, last_comment_date, latest_comment, vfa_update, vfa_update_date, station_id) FROM stdin;
7	\N	GENERAL FUND - 011-9505-000365	\N	846600.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $846,600.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: -$610.00\nINT. - REVISED BUDGET: $845,990.00\nREQUISITIONS: $106,555.10\nPROJECT BAL. / SAVINGS: $739,434.90	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
8	\N	21st Fountain Sod Cover	Adding of a rock surface to cover the overspray of the fountain	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Frank Dome	\N	\N	ZONE: AUX\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
9	\N	777 Exterior Lighting Outlets	Electrical Upgrades	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Giacomo Natteri	\N	\N	ZONE: CC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
10	\N	777 Water Fountain Upgrades	Water Fountain/ Bottle Filler	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Giacomo Natteri	\N	\N	ZONE: CC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
11	\N	777 2nd Floor Fire Prevention Conference room Flooring	Flooring / LVT	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Giacomo Natteri	\N	\N	ZONE: CC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
12	\N	City Hall HR Lighting Upgrades	Lighting upgrades	24900.00	0.00	IN PROGRESS	\N	2025-11-05	\N	\N	Giacomo Natteri	\N	\N	ZONE: CC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: -$14,236.89\nINT. - REVISED BUDGET: $10,663.11\nREQUISITIONS: $10,663.11\nPROJECT BAL. / SAVINGS: $0.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
13	\N	R&D - PO# 20260920	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	REQUISITIONS: $10,663.11	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
14	\N	TRANSFER OUT	TO *NEW* 777 Building Wall Leak Repairs	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	INTERNAL TRANSF. IN / OUT: -$14,236.89	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
15	\N	*NEW* 777 Building Wall Leak Repairs	*NEW* 777 Building Wall Leak Repairs	0.00	0.00	IN PROGRESS	\N	2025-12-17	\N	\N	Giacomo Natteri	\N	\N	ZONE: CC\nMUNIS ADOPTED/AMENDED: $0.00\nINTERNAL TRANSF. IN / OUT: $14,236.89\nINT. - REVISED BUDGET: $14,236.89\nREQUISITIONS: $14,236.89\nPROJECT BAL. / SAVINGS: $0.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
16	\N	TRANSFER IN	FROM City Hall HR Lighting Upgrades	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	INTERNAL TRANSF. IN / OUT: $14,236.89	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
17	\N	A&J ROOFING - PO# 2026XXXX	PARTIALLY FUNDED WITH 520-1720-000342 ($3,751.61)	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	REQUISITIONS: $14,236.89	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
18	\N	City Hall HVAC Controls 1st Floor	HVAC controls for 1st floor of City Hall	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Giacomo Natteri	\N	\N	ZONE: CC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
19	\N	GREEN ALLIANCE - PO# 20261522	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	REQUISITIONS: $8,690.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
20	\N	GREEN ALLIANCE - PO# 20261523	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	REQUISITIONS: $12,430.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
21	\N	GREEN ALLIANCE - PO# 2026XXXX	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	REQUISITIONS: $6,700.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
22	\N	Flamingo Park Pool Concession painting and water proofing	Paint and water proof flamingo park pool	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Cody Croye	\N	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
23	\N	Green Space Interior Painting	painting the interior of the Green Space warehouse	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Cody Croye	\N	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
24	\N	MBPD Community Room	Interior painting and wall repairs	24900.00	0.00	IN PROGRESS	\N	2025-12-11	\N	\N	Faustino Fernandez	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov  req entered	\N	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $6,490.00\nPROJECT BAL. / SAVINGS: $18,410.00\nLAST COMMENT DATE: 12/08/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
25	\N	MBPD Internal Affairs Engineering Repairs	Repairs needed as part of the 40 yrs certification	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Faustino Fernandez	\N	\N	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
26	\N	MBPD Police	Storage organization and upgrades to evidence rooms and hurricane supply storage room	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Faustino Fernandez	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov  getting quotes	\N	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 12/04/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
3	FIRE-5315	Fire Station # 2 - Administration Building	Flooring renovation and upgrades	24900.00	0.00	NOT STARTED	Medium	\N	\N	\N	Faustino Fernandez	\N	50	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 00:18:45	2026-01-29 19:28:09	PS		24900.00	0.00	24900.00	0.00	24900.00	0.00	0.00	24900.00	\N			\N	30
27	\N	MBPD NESS	Interior painting and wall repairs	24900.00	0.00	IN PROGRESS	\N	2025-12-10	\N	\N	Faustino Fernandez	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov  req entered	\N	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $15,321.60\nPROJECT BAL. / SAVINGS: $9,578.40\nLAST COMMENT DATE: 12/03/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
28	\N	EAGLE PAINT - J&J - PO# 2026XXXX	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	REQUISITIONS: $15,321.60	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
29	\N	MBPD Police Scissor Lift (Equipment)	Fork Lift for use at PD station	24900.00	0.00	CANCELLED	\N	\N	\N	\N	Faustino Fernandez	Stephany Gonzales - Per FZM, this purchase is no needed.  Funds will be reallocated for other purposes.	\N	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: -$23,913.75\nINT. - REVISED BUDGET: $986.25\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $986.25\nLAST COMMENT DATE: 11/03/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
30	\N	*NEW* MBPD Cooling Tower louver replacement	*NEW* MBPD Cooling Tower louver replacement	0.00	0.00	IN PROGRESS	\N	2025-11-03	\N	\N	Faustino Fernandez	\N	\N	ZONE: PS\nMUNIS ADOPTED/AMENDED: $0.00\nINTERNAL TRANSF. IN / OUT: $23,913.75\nINT. - REVISED BUDGET: $23,913.75\nREQUISITIONS: $23,913.75\nPROJECT BAL. / SAVINGS: $0.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
31	\N	MASTER MECHANICAL - PO# 2026XXXX	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	REQUISITIONS: $23,913.75	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
32	\N	Miami Beach Golf Course Counter top replacement	Counter top replacement for Miami Beach Golf Course	24900.00	0.00	COMPLETED	\N	\N	\N	\N	Frank Dome	Stephany Gonzales - This project was completed in FY 2025 under PO# 20253007 (under $25K funds).	\N	ZONE: AUX\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: -$24,900.00\nINT. - REVISED BUDGET: $0.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $0.00\nLAST COMMENT DATE: 10/16/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
33	\N	1	REALLOCATED FOR PARKS & REC PROJECT > Normandy Shores Golf Course Line Cooler Repairs	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	INTERNAL TRANSF. IN / OUT: -$24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
34	\N	*NEW* Mami Beach Pro Shop AC replacement	*NEW* Mami Beach Pro Shop AC replacement	0.00	0.00	IN PROGRESS	\N	2025-10-21	\N	\N	Frank Dome	Stephany Gonzales - This project was completed in FY 2025 under PO# 20253007 (under $25K funds).	\N	ZONE: AUX\nMUNIS ADOPTED/AMENDED: $0.00\nINTERNAL TRANSF. IN / OUT: $24,900.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 10/21/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
35	\N	Miami Beach Golf Course Kitchen Equipment upgrade	New Bar equipment for Miami Beach Golf Course	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Frank Dome	\N	\N	ZONE: AUX\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
36	\N	Normandy Shores Golf Course Kitchen Equipment upgrade	New Bar equipment for Normandy Shores Golf Course	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Frank Dome	\N	\N	ZONE: AUX\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
37	\N	Normandy Shores Golf Course Exterior lighting upgrade	Exterior lighting Upgrades for Normandy Shores Golf Course	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Frank Dome	\N	\N	ZONE: AUX\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
38	\N	Normandy Fountain Lighting Upgrades	Replacement and adding of lights to Normandy Fountain	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Frank Dome	\N	\N	ZONE: AUX\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
39	\N	Normandy Isle Pool Interior Painting	painting the interior of the Normandy Isle Pool	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Cody Croye	\N	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
40	\N	Ocean Rescue HQ	Storage upgrades and renovation	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Faustino Fernandez	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov  this one will not proceed as Chief Mestas does not need it anymore	\N	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
41	\N	Ocean Rescue 79th Street	GYM upgrades	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Faustino Fernandez	\N	\N	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
42	\N	PAL water fountain replacement	Replacement of all water fountains inside of the PAL building	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Cody Croye	\N	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
43	\N	PAL Interior Painting	Interior painting of the PAL building	24900.00	0.00	CANCELLED	\N	\N	\N	\N	Cody Croye	Stephany Gonzales - This project was completed using operating funds the previous year per FZM.	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: -$24,900.00\nINT. - REVISED BUDGET: $0.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $0.00\nLAST COMMENT DATE: 10/21/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
44	\N	South Point Park Building / Concessions painting	Paint and water proof South point park building	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Cody Croye	\N	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
45	\N	Scott Rakow Insulation repairs	repairing and replacing insulation inside of Scott Rakow	24900.00	0.00	CANCELLED	\N	\N	\N	\N	Cody Croye	Stephany Gonzales - Per FZM this project was completed in FY 2025 using operating funds. We will reallocate these funds for other purposes.	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: -$8,109.75\nINT. - REVISED BUDGET: $16,790.25\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $16,790.25\nLAST COMMENT DATE: 01/20/26	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
46	\N	Scott Rakow AC Duct Repairs	repairing and replacing AC ducts inside of Scott Rakow	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Cody Croye	\N	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
47	\N	*NEW* North Shore Community Center - 1st Floor Motor + Bearings	*NEW* North Shore Community Center - 1st Floor Motor + Bearings	0.00	0.00	IN PROGRESS	\N	2026-01-20	\N	\N	Cody Croye	\N	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $0.00\nINTERNAL TRANSF. IN / OUT: $8,109.75\nINT. - REVISED BUDGET: $8,109.75\nREQUISITIONS: $8,109.75\nPROJECT BAL. / SAVINGS: $0.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
48	\N	South Shore Community Center Lighting Upgrade	Interior lighting upgrades to South Shore Community Center	24900.00	0.00	CANCELLED	\N	\N	\N	\N	Cody Croye	Stephany Gonzales - This work was done using operating funds in previous year per FZM.	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: -$24,290.00\nINT. - REVISED BUDGET: $610.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $610.00\nLAST COMMENT DATE: 10/21/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
49	\N	*NEW* South Shore Community Center Roof Drain Line Replacement	*NEW* South Shore Community Center Roof Drain Line Replacement	0.00	0.00	IN PROGRESS	\N	2025-10-21	\N	\N	Cody Croye	Stephany Gonzales - This work was done using operating funds in previous year per FZM.	\N	ZONE: RCC\nMUNIS ADOPTED/AMENDED: $0.00\nINTERNAL TRANSF. IN / OUT: $48,580.00\nINT. - REVISED BUDGET: $48,580.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $48,580.00\nLAST COMMENT DATE: 10/21/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
50	\N	RDA CITY CENTER FUND - 168-9964-000365	\N	44900.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $44,900.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $44,900.00\nREQUISITIONS: $18,600.80\nPROJECT BAL. / SAVINGS: $26,299.20	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
51	\N	Lincoln Road Electrical Outlet Upgrades	Electrical Upgrades	24900.00	0.00	CANCELLED	\N	\N	\N	\N	Giacomo Natteri	Stephany Gonzales - this project was done using operating funds in FY 2025 per FZM.	\N	ZONE: RDA\nMIAMI BEACH AREA: SB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: -$18,600.80\nINT. - REVISED BUDGET: $6,299.20\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $6,299.20\nLAST COMMENT DATE: 10/21/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
52	\N	*NEW* BASS MUSEUM Building Envelope Repairs	*NEW* BASS MUSEUM Building Envelope Repairs	0.00	0.00	IN PROGRESS	\N	2025-10-21	\N	\N	Giacomo Natteri	\N	\N	ZONE: RDA\nMIAMI BEACH AREA: SB\nMUNIS ADOPTED/AMENDED: $0.00\nINTERNAL TRANSF. IN / OUT: $18,600.80\nINT. - REVISED BUDGET: $18,600.80\nREQUISITIONS: $18,600.80\nPROJECT BAL. / SAVINGS: $0.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
53	\N	A&J ROOFING - PO# 20253890	A & J ROOFING CORP - BASS Museum - Building Envelope Repairs	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	REQUISITIONS: $18,600.80	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
54	\N	Lincoln Road Pond Aquatic Life	Aquatic Life (Fish)	20000.00	0.00	NOT STARTED	\N	\N	\N	\N	Giacomo Natteri	\N	\N	ZONE: RDA\nMIAMI BEACH AREA: SB\nMUNIS ADOPTED/AMENDED: $20,000.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $20,000.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $20,000.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
55	\N	RDA - ANCHOR GARAGE SHOPS FUND - 465-1995-000365	\N	20000.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $20,000.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $20,000.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $20,000.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
56	\N	Anchor Shops awning replacement	Replacement of awning at retail spaces in Anchor Garage	20000.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: SB\nMUNIS ADOPTED/AMENDED: $20,000.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $20,000.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $20,000.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
57	\N	SANITATION FUND - XXXX	\N	24900.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $24,900.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
58	\N	Sanitation Bay (Fleet) - LED lights retrofit	LED Lights retrofit	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Faustino Fernandez	\N	\N	ZONE: PS\nMIAMI BEACH AREA: SB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
59	\N	7TH ST GARAGE FUND - 142-6976-000365	\N	49800.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $49,800.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $49,800.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $49,800.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
60	\N	7th St. garage elevator tower roof railings replacement	due to rust, replace elevator towers roof railings with aluminum railings	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: SB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
61	\N	7th St. garage trash bins replacement	replace all trash bins in the garage	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: SB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
62	\N	RDA - ANCHOR GARAGE FUND - 463-1990-000365	\N	24900.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $24,900.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
63	\N	16th St. garage trash bins replacement	replace all trash bins in the garage	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: SB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
64	\N	PENSYLVANIA GARAGE OPERATIONS FUNDS - 467-1996-000365	\N	74700.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $74,700.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $74,700.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $74,700.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
65	\N	Pennsylvania garage domestic water pump replacement	domestic water pump replacement	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
66	\N	Pennsylvania garage mech room doors replacement	replace doors due to corrosion	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
67	\N	Pennsylvania garage office floor and furniture replacement	replace the floor tiles and furniture, wall repair and paint.	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
68	\N	COLLINS PARK FUNDS - XXX-XXXX-000365	\N	39900.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $39,900.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $39,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $39,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
69	\N	Collins Park garage alley doors replacement	replace the 2 alley doors with panic bars and hinges due to corrosion	15000.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $15,000.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $15,000.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $15,000.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
70	\N	Collins Park Garage elevator traction belts replacement	to replace old rubber traction belts	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
71	\N	PARKING OPERATIONS FUNDS - 480-0463-000365	\N	174300.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $174,300.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $174,300.00\nREQUISITIONS: $35,143.66\nPROJECT BAL. / SAVINGS: $139,156.34	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
72	\N	12th St. garage office renewal and furniture replacement	replace floor tiles, paint walls, replace all furniture and drop ceiling	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
73	\N	12th St. garage office restroom renewal	replace tiles, sink, and toilet. Paint walls and replace drop ceiling	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
74	\N	13th St. garage perimeter grid fence repair and paint	repair and repaint all ground level grid fence	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
75	\N	1755 garage exit signs replacement	replace all exit signs	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
76	\N	17th St. garage exit signs replacement	replace all exit signs	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
77	\N	17th St. garage trash bins replacement	replace all trash bins in the garage	24900.00	0.00	CANCELLED	\N	\N	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: -$10,393.66\nINT. - REVISED BUDGET: $14,506.34\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $14,506.34\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
78	\N	*NEW* 17th Street Parking Garage Spalling Repairs	*NEW* 17th Street Parking Garage Spalling Repairs	0.00	0.00	IN PROGRESS	\N	2025-10-21	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $0.00\nINTERNAL TRANSF. IN / OUT: $10,393.66\nINT. - REVISED BUDGET: $10,393.66\nREQUISITIONS: $10,393.66\nPROJECT BAL. / SAVINGS: $0.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
79	\N	Sunset Harbor garage dilapidation repair and waterproofing	Repair concrete dilapidation and waterproofing	24900.00	0.00	IN PROGRESS	\N	2025-11-06	\N	\N	Charles Premdas	\N	\N	ZONE: PRK\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $24,750.00\nPROJECT BAL. / SAVINGS: $150.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
80	\N	FLEET MGMT INTERNAL SVCES - 510-1780-000365	\N	49800.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $49,800.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $49,800.00\nREQUISITIONS: $24,422.95\nPROJECT BAL. / SAVINGS: $25,377.05	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
81	\N	Fleet Management IT Room improvements	IT Room improvements	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Faustino Fernandez	\N	\N	ZONE: PS\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
82	\N	Fleet Management restroom renovation	Restrooms renovation and upgrades	24900.00	0.00	IN PROGRESS	\N	2025-10-27	\N	\N	Faustino Fernandez	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov  work finished in 2 restrooms	\N	ZONE: PS\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $24,422.95\nPROJECT BAL. / SAVINGS: $477.05\nLAST COMMENT DATE: 12/04/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
83	\N	FACILITIES MGMT INTERNAL SVCES - FUND 520-1720-000365	\N	24900.00	0.00	\N	\N	\N	\N	\N	\N	\N	\N	MUNIS ADOPTED/AMENDED: $24,900.00\nMUNIS TRANSF. IN / OUT: $0.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
84	\N	Facilities Management Scissor Lift (Equipment)	Scissor Lift	24900.00	0.00	NOT STARTED	\N	\N	\N	\N	Faustino Fernandez	\N	\N	ZONE: PS\nMIAMI BEACH AREA: MB\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00\nLAST COMMENT DATE: 07/22/25	\N	\N	2026-01-29 01:50:35	2026-01-29 01:50:35	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N
1	FIRE-0105	Fire Station # 1	Watch Office Renovation/ Upgrades	24900.00	0.00	IN PROGRESS	Medium	2025-12-11	\N	\N	Faustino Fernandez	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov yes	50	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: -$12,430.00\nINT. - REVISED BUDGET: $12,470.00\nREQUISITIONS: $8,690.00\nPROJECT BAL. / SAVINGS: $3,780.00\nLAST COMMENT DATE: 01/20/26	\N	\N	2026-01-29 00:18:45	2026-01-29 19:28:09	PS		24900.00	0.00	24900.00	-12430.00	12470.00	8690.00	0.00	3780.00	2026-01-20	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov yes		\N	29
4	FIRE-3874	Fire Station # 2	GYM upgrades	24900.00	0.00	IN PROGRESS	Medium	2025-12-11	\N	\N	Faustino Fernandez	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov  req entered	50	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $6,700.00\nPROJECT BAL. / SAVINGS: $18,200.00\nLAST COMMENT DATE: 12/08/25	\N	\N	2026-01-29 00:18:45	2026-01-29 19:28:09	PS		24900.00	0.00	24900.00	0.00	24900.00	6700.00	0.00	18200.00	2025-12-08	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov req entered		\N	30
2	NEW-3102	*NEW* Fire Station # 3	Watch Office Renovation/ Upgrades	0.00	0.00	IN PROGRESS	Medium	2025-12-11	\N	\N	Faustino Fernandez	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov  PO ready, coordinating to start work	50	ZONE: PS\nMUNIS ADOPTED/AMENDED: $0.00\nINTERNAL TRANSF. IN / OUT: $12,430.00\nINT. - REVISED BUDGET: $12,430.00\nREQUISITIONS: $12,430.00\nPROJECT BAL. / SAVINGS: $0.00\nLAST COMMENT DATE: 01/20/26	\N	\N	2026-01-29 00:18:45	2026-01-29 19:28:09	PS		0.00	0.00	0.00	12430.00	12430.00	12430.00	0.00	0.00	2026-01-20	Faustino Fernandez - @stephanygonzales@miamibeachfl.gov PO ready, coordinating to start work		\N	31
5	FIRE-8308	Fire Station # 3	Kitchen equipment upgrades	24900.00	0.00	NOT STARTED	Medium	\N	\N	\N	Faustino Fernandez	\N	50	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 00:18:45	2026-01-29 19:28:09	PS		24900.00	0.00	24900.00	0.00	24900.00	0.00	0.00	24900.00	\N			\N	31
6	FIRE-0889	Fire Station # 4	Self Cleaning Kitchen Hood repair	24900.00	0.00	NOT STARTED	Medium	\N	\N	\N	Faustino Fernandez	\N	50	ZONE: PS\nMUNIS ADOPTED/AMENDED: $24,900.00\nINTERNAL TRANSF. IN / OUT: $0.00\nINT. - REVISED BUDGET: $24,900.00\nREQUISITIONS: $0.00\nPROJECT BAL. / SAVINGS: $24,900.00	\N	\N	2026-01-29 00:18:45	2026-01-29 19:28:09	PS		24900.00	0.00	24900.00	0.00	24900.00	0.00	0.00	24900.00	\N			\N	32
\.


--
-- Data for Name: uniforms; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.uniforms (id, item_name, size, quantity_on_hand, reorder_level, unit_cost, supplier, notes, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: unit_master_vehicles; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.unit_master_vehicles (id, veh_number, make, model, year, tag_number, dept_code, employee_or_vehicle_name, sunpass_number, als_license, serial_number, section, assignment, location, created_at, updated_at) FROM stdin;
1	1665	Ford	Taurus SE	2013	XD0409	1210	Capt. A. Garcia	73068501107	N/A	1FAHP2D85DG117005	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
2	19500	Ford	Explorer	2020	XH9356	1210	Chief White	1480001010	N/A	1FMSK8BH7LGB07283	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
3	19501	Ford	Explorer	2020	XH9357	1210	Sergio Martinez	7581991010	N/A	1FMSK8BH5LGB07282	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
4	16904	Ford	Explorer	2016	XE3998	1210	Turned in Vic	284968310104	N/A	1FM5K7B86GGD05107	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
5	20500	Ford	Fusion	2020	XI6317	1210	Jerry De Young	162236301105	N/A	3FA6P0LU9LR238069	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
6	22500	Ford	Escape	2022	XJ7276	1210	S. Frosceno (At Admin)		N/A	1FMCU0EZXNUA84591	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
7	22510	Ford	Escape	2022	XJ7264	1230	Alfredo Garcia	1052833501105	N/A	1FMCU0EZ6NUA84006	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
8	22517	Ford	Escape	2023	XJ7271	1230	Spare		N/A	1FMCU0EZ8NUA84752	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
9	22519	Ford	Explorer	2022	XJ7876	1210	Laz Guerra		N/A	1FM5K8FW5NNA04660	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
10	22520	Ford	Explorer	2022	XJ7875	1210	J. Bloomfield		N/A	1FM5K8FW7NNA04658	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
11	22521	Ford	Explorer	2022	XJ8094	1210	Jorge Linares		N/A	1FM5K8FW3NNA04544	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
12	22524	Ford	Escape	2022	XJ8101	1240	Spare (Mason)		N/A	1FMCU0EZ0NUB49349	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
13	23500	Chevy	Tahoe	2023		1210	Chief Abello	640816310100	N/A		Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
14	23501	Toyota	Highlander	2023	XJ9431	1210	Michelle Henson				Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
15	23503	Toyota	RAV4	2023	XJ9429	1210	Captain Richard Quintela	665682710106	N/A		Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
16	23504	Toyota	RAV4	2023	XJ9428	1210	F. Betancourt	665682710107	N/A	4T3LWRFV7PU104610	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
17	24502	TOYOTA	HIGHLANDER HYBRID	2024	XK6567	325	S. Lipner		N/A	5TDBBRCH1RS623727	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
18	24504	TOYOTA	HIGHLANDER HYBRID	2024	XK6568	1230	D. Sola	578434910104	N/A	5TDBBRCH0RS623945	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
19	24505	TOYOTA	HIGHLANDER HYBRID	2024	XK6569	1230	J. Mestas		N/A	5TDBBRCH8RS624390	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
20	24506	GMC	1500	2024	XK6580	1240	M. Anchia		N/A	1GTPUAEK1RZ203992	Fire Administration	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
21	15502	Ford	Explorer	2016	XE3816	1230	Captain C. Chavez	653346401107	N/A	1FM5K7D82GGA77653	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
22	15504	Ford	Transit Van	2015	XE3858	1230	Pub Ed Van	20102101100	N/A	1FTYE1YM6GKA01329	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
23	15505	Ford	Focus	2016	XE3857	1230	Deadline	522578501102	N/A	1FADP3F20FL342813	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
24	20501	Ford	Fusion	2020	XI1585	1230	Claudio Navas	653347301105	N/A	3FA6P0LU6LR237896	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
25	21500	Ford	Escape	2021	XI6317	1230	Osvaldo Iglesias		N/A	1FMCU0BZ3MUA83799	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
26	22502	Ford	Escape	2022	XJ4164	1230	Israel Perez		N/A	1FMCU0EZXNUA84641	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
27	22503	Ford	Escape	2022	XJ4165	1230	Steven Mills	1052836101100	N/A	1FMCU0EZ6NUA85088	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
28	22504	Ford	Escape	2022	XJ4166	1230	Jason Bogk		N/A	1FMCU0EZ4NUA83923	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
29	22505	Ford	Escape	2022	XJ7259	1230	Prev Spare (Miguel)		N/A	1FMCU0EZ1NUA84737	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
30	22506	Ford	Escape	2022	XJ7260	1230	Elier Marquez		N/A	1FMCU0EZ2NUA84584	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
31	22507	Ford	Escape	2022	XJ7261	1230	Steve Munoz		N/A	1FMCU0EZ5NUA83963	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
32	22508	Ford	Escape	2022	XJ7262	1230	Lt. Isabel Ochoa		N/A	1FMCU0EZ8NUA85495	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
33	22509	Ford	Escape	2022	XJ7263	1230	Tony Llizo		N/A	1FMCU0EZXNUA84820	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
34	22511	Ford	Escape	2022	XJ7265	1230	Jose Lazcano		N/A	1FMCU0EZ2NUA83726	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
35	22512	Ford	Escape	2022	XJ7266	1230	Joseph Bacallao		N/A	1FMCU0EZXNUA84669	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
36	22513	Ford	Escape	2022	XJ7267	1230	Jorge Diaz		N/A	1FMCU0EZ7NUA84581	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
37	22514	Ford	Escape	2022	XJ7268	1230	Kevin Darley		N/A	1FMCU0EZ9NUA83416	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
38	22515	Ford	Escape	2022	XJ7269	1230	Raul Fernandez		N/A	1FMCU0EZ1NUA84513	Fire Prevention	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
39	22516	Ford	Escape	2022	XJ7270	1230	Carlos Quesada PSCD		N/A	1FMCU0EZ7NUA84421	PSCD	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
40	22518	Ford	Escape	2022	XJ0698	326	Jeniffer Hall-Jenkins	570838910108	N/A	1FMCU0EZ1NUA83782	PSCD	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
41	22522	Ford	Escape	2022	XJ8096	326	Traci Cadet	570839010104	N/A	1FMCU0EZ6NUB50263	PSCD	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
42	002-12	PIERCE	DASH-2000	2006	XA2810	1210	LADDER 1	1599247501106	18582	4P1CD01E86A006490	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
43	20503	PIERCE	VELOCITY	2021	XI5853	1210	ENGINE 1		23822	4P1BAAGF6MA022940	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
44	002-14	PIERCE	VELOCITY	2009	XB8049	1210	RESERVE ENGINE 1	508887001100	18579	4P1CV01E19A009314	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
45	16508	FREIGHTLINER	M2106	2015	XE4036	1220	RESCUE 1	508887101109	20030	1FVACWDUXGHHR7386	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
46	14500	FREIGHTLINER	M2	2014	XD7585	1220	RESERVE RESCUE	508887201108	18513	1FVACWDU7EHFX1481	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
47	002-18	PIERCE	VELOCITY	2012	XD1755	1210	RESERVE ENGINE 21	508887301107	17741	4P1CV01N7CA013083	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
48	16507	FREIGHTLINER	M2106	2016	XE4031	1220	RESCUE 2	485270901101	23129	1FVACWDU8GHHR7385	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
49	14501	FREIGHTLINER	M2	2014	XD7586	1220	RESERVE RESCUE	1599247001101	18512	1FVACWDU9EHFX1482	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
50	002-6	PIERCE	DASH-2000	2001	208338	1210	RESERVE LADDER	1599246501109	18580	4P1CT02E71A001419	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
51	002-22	PIERCE	VELOCITY	2014	XD7571	1210	ENGINE 3	1599246401100	18511	4P1CV01N4EA014369	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
52	1034	FREIGHTLINER	M2106	2009	XB8113	1220	RESERVE RESCUE	1599245101106	15815	1FVACWDU79HAK8704	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
53	20504	PIERCE	VELOCITY	2021	XI5852	1210	ENGINE 4		23821	4P1BAAGF4MA022967	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
54	002-16	PIERCE	VELOCITY	2011	XC9190	1210	ENGINE 2	1599246201102	17331	4P1CV01N8BA012376	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
55	1035	FREIGHTLINER	M2106	2011	XD1671	1220	RESERVE RESCUE	1599246601108	17330	1FVACWDU3BDBA7263	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
56	17502	FREIGHTLINER	M2106	2017	XG1579	1220	RESCUE 4	522577701103	21229	1FVACWFDOJHJU1506	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
57	1031	FREIGHTLINER	M2106	2009	XB8115	1220	RESERVE RESCUE	1599247301108	15816	1FVACWDU69HAG7747	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
58	17503	FREIGHTLINER	M2106	2017	XG1577	1220	RESCUE 44	522577601104	21228	1FVACWFD9JHJU1505	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
59	16501	MetalShark	28' COURAGEOUS	2016	FL0503RD	1210	Fire Boat	Does Not Need	N/A	GAJ28C01C616	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
60	16504	EVOLUTION	28C	2016	XE3994	1210	Fire Boat Trailer	Does Not Need	N/A	1E9E1BA22GF513482	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
61	002-20	PIERCE	CONTENDER	2012	XD1753	1210	AIR TRUCK	1599245501102	N/A	4P1CC01A9CA012603	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
62	13564	Ford	Expedition	2014	XD1905	1210	RESERVE EXPEDITION	1572319401104	N/A	1FMJK1J52EEF10922	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
63	17500	Ford	Expedition	2017	XF6753	1210	300	522578001107	N/A	1FMJK1GT4HEA75122	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
64	22530	Ford	F-250	2022	XD1904	1220	CAPTAIN 5		N/A		Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
65	13500	Ford	F-250	2014	XD1799	1240	SHOP TRUCK	1599245601101	N/A	1FTBF2A66DEB58647	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
66	17501	FREIGHTLINER	M2106	2017	XG1580	1220	RESCUE 3	5225781021106	21227	1FVACWFD7JHJU1504	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
67	17505	PIERCE	ARROW XT	2018	XG2179	1210	LADDER 3	523338401102	21586	4P1BCAFG2JA018826	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
68	002-10	PIERCE	DASH-R	2002	216608	1210	RESERVE ENGINE	1599245801109	20032	4P1CT02E12A002406	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
69	002-4	PIERCE	DASH-2000	2001	203494	1210	RESERVE ENGINE	1599245901108	11187	4P1CT02EX1A001298	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
70	19502	FREIGHTLINER	M2106	2020	XI0278	1220	RESCUE 11			1FVACWFD6LHKY8464	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
71	19503	FREIGHTLINER	M2106	2020	XI0279	1220	RESCUE 22			1FVACWFD8LHKY8465	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
72	1033	FREIGHTLINER	M2106	2009	XB8114	1220	RESERVE RESCUE	1599246701107	15817	1FVACWDU99HAK8705	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
73	1036	FREIGHTLINER	M2106	2011	XD1670	1220	RESERVE RESCUE	1599247401107	18581	1FVACWDU5BDBA7264	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
74	1027	Wells Cargo	CVG3239	2006	230181	1240	Flusar Trailer	Does Not Need	N/A	1WC200R3763054938	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:54	2026-01-28 15:14:54
75	1028	Wells Cargo	CVG3239	2006	230182	1240	Maze Trailer	Does Not Need	N/A	1WC200R3963054939	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
76	17504	Wells Cargo	CT7X122	2017	XF6771	1240	MCI Trailer	Does Not Need	N/A	575200E21HH348496	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
77	18500	Ford	F550	2018		1210	AIR TRUCK #2	69185610105	N/A	1FD0W5GT4JEC13755	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
78	21501	Ford	F-350 XL	2022	XI6318	1240	SHOP TRUCK		N/A	1FTRF3C66NEC15822	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
79	21502	Ford	F-450	2022	XI6365	1240	FLUSAR DULLY		N/A	1FT8W4DT5NEC27305	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
80	21588	John Deere	XUV 835M	2023	N/A	1220	Gator Spare (Sta 2)	Does Not Need	N/A	1M0835MALPM065887	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
81	21589	John Deere	XUV 835M	2023	N/A	1220	Gator Spare (Sta 2)	Does Not Need	N/A	1M0835MAHPM065888	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
82	21590	John Deere	XUV 835M	2023	N/A	1220	Gator 4	Does Not Need	N/A	1M0835MAEPM065889	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
83	21591	John Deere	XUV 835M	2023	N/A	1220	Gator Spare (Sta 2)	Does Not Need	N/A	1M0835MAHPM065891	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
84	21592	John Deere	XUV 835M	2023	N/A	1220	Gator 2	Does Not Need	N/A	1M0835MALPM065890	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
85	21593	John Deere	XUV 835M	2023	N/A	1220	Gator 3	Does Not Need	N/A	1M0835MAAPM065886	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
86	24507	Ford	F250	2024		1220					Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
87	24508	Ford	F250	2024		1220					Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
88	24509	PIERCE	VELOCITY	2025		1210	new E2			4P1BAAGF8RA027581	Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
89	4760	Ford	F150	2010		325	DEM				Frontline and Reserve Fire Apparatus	\N	\N	2026-01-28 15:14:55	2026-01-28 15:14:55
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at, display_name, rank, station, phone, must_change_password, is_admin, panel, plain_password) FROM stdin;
30	Millyes Gomez	millyesgomez@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-07 23:21:16	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
5	Admin	mbfdsupport@gmail.com	\N	$2y$12$IZxlAGqcwsSEnvucF1qwOuJlHTZlIsHr22WwYqId4oGCqKTK94xtK	\N	2026-01-26 08:55:34	2026-01-26 08:55:34	Admin	\N	\N	\N	f	f	admin	\N
31	Jesus Abay	jesusabay@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-07 23:21:16	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
7	Test User	test@example.com	2026-01-26 16:28:27	$2y$12$vp88KeTsc12jon6sFWj6e.5N8Yz9lcIspK3whOpK2y1rCOiP9h.RO	rDvCA0nGUh	2026-01-26 16:28:28	2026-01-26 16:28:28	\N	\N	\N	\N	f	f	admin	\N
32	Timothy Barreto	timothybarreto@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-07 23:21:16	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
1	Miguel Anchia	miguelanchia@miamibeachfl.gov	\N	$2y$12$wCfZahoa9hvH1HMmD4lcge9HxWgmW2UM80EAt13Q28mmoJrjWUGq2	wrmf37ahVH7dShMY5FI5nSDLEjEPl8vQbTkPblc5UcAt1MEspsSZKJE3dgwW	2026-01-25 14:38:43	2026-03-06 23:40:56	\N	\N	\N	\N	f	f	admin	\N
2	Richard Quintela	richardquintela@miamibeachfl.gov	\N	$2y$12$nBr87/QjNHM03wtMWOy1Z.2mVYdnlvDO3QReZSkjm.WYuaWsWM9Wa	rtyDYozdtuSCmLYSzy9SBoe4QZAvRl9veSsdFIHASOoL9CIa4BhYF6wSgede	2026-01-25 14:38:44	2026-03-06 23:40:56	\N	\N	\N	\N	f	f	admin	\N
3	Peter Darley	peterdarley@miamibeachfl.gov	\N	$2y$12$6iucmLnNBymIX21Qa6QYTOt6pOTlEeiFYvaU3zcTSUuM2fxKdZhA6	0NiK04QWWvn1tU7bvuu329hNw2hQzZ9CmLk8NUnU0VwMzE4a1nZboVMl7NVu	2026-01-25 14:38:44	2026-03-06 23:40:57	Peter Darley	Lieutenant	Admin	7863069955	f	f	admin	\N
4	Gerald DeYoung	geralddeyoung@miamibeachfl.gov	\N	$2y$12$2ZnLyYWTRp0jyrrkQ4OEOO2ha/vhwSzK1dCnPmz3DuycOUIGSc6yC	\N	2026-01-25 14:38:44	2026-03-06 23:40:57	\N	\N	\N	\N	f	f	admin	\N
33	Marcio Bueno	marciobueno@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-07 23:21:16	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
19	David Crespo	davidcrespo@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-02 12:51:08	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
20	Jose Flores	joseflores@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-02 18:02:55	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
21	Austin Glassman	austinglassman@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-03 11:18:51	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
22	Alejandro Trujillo	alejandrotrujillo@miamibeachfl.gov	2026-03-06 13:23:10	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-04 18:28:32	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
23	Luis Cruzado	luiscruzado@miamibeachfl.gov	2026-03-06 13:23:10	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-05 14:32:42	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
11	Daniel Gato	danielgato@miamibeachfl.gov	\N	$2y$12$5PUNnERwMBsKw9uH1HoozOKKcskeKuklAO4W4m10fOweHu6N03qrm	\N	2026-02-09 12:42:34	2026-03-06 23:40:57	\N	\N	\N	\N	f	f	training	\N
13	Victor White	victorwhite@miamibeachfl.gov	\N	$2y$12$A6Nk26GwK9pgUBaSRT.TxOlRGsJf/AA1Te8UFGs9vyD7Mp31cLdnG	\N	2026-02-09 12:42:34	2026-03-06 23:40:58	\N	\N	\N	\N	f	f	training	\N
14	Claudio Navas	claudionavas@miamibeachfl.gov	\N	$2y$12$PiH8V9jDzsbaJSlYZyWtt.UQN5beT3ZSfC9QBylBgihGufDTiIxce	\N	2026-02-09 12:42:35	2026-03-06 23:40:58	\N	\N	\N	\N	f	f	training	\N
12	Michael Sica	michaelsica@miamibeachfl.gov	\N	$2y$12$wO8oZdOc.pG1TrKqVvApAuWCVN22UouQEmCemS517S62BcBDiMcGS	\N	2026-02-09 12:42:34	2026-03-06 23:40:58	\N	\N	\N	\N	f	f	training	\N
28	David Garcia	davidgarcia@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-07 23:21:16	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
29	Alexander Nunez	alexandernunez@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-07 23:21:16	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
24	Nicolas D'Alessandro	nicolasdalessandro@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-05 15:06:44	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
25	Carlos Vinuela	carlosvinuela@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-05 15:07:19	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
26	Evelio Aleman	evelioaleman@miamibeachfl.gov	2026-03-06 13:23:10	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-05 15:08:03	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
27	Anthony Amoruso	anthonyamoruso@miamibeachfl.gov	\N	$2y$12$GDNwCaoQMDaFgQSoD495LudwgOBTpQHxF6m.nf4OzvvRqKQefLv4W	\N	2026-03-05 15:52:31	2026-03-07 23:21:16	\N	\N	\N	\N	f	f	admin	\N
6	Grecia Trabanino	greciatrabanino@miamibeachfl.gov	\N	$2y$12$NicflXqWgfRJwyTkt0OawuCyYZgPNcQJ3VUCJNJ92jCBGDskP986W	4ebuKTyvnVR6vbboC36nMLks5hwSNt3vF7YSG3PcbNBiNOW7lYvzcMB1eJZo	2026-01-26 13:30:16	2026-03-06 23:40:57	Grecia Trabanino	\N	Admin	\N	f	f	admin	\N
\.


--
-- Data for Name: workgroup_files; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.workgroup_files (id, workgroup_id, workgroup_session_id, filename, filepath, file_type, file_size, uploaded_by, created_at, updated_at) FROM stdin;
2	2	1	TNT RESCUE Surge Catalog Binder.pdf	workgroup-files/01KJQ9DMC96P803Z3HGDGS1ADM.pdf	pdf	\N	3	2026-03-02 12:47:25	2026-03-02 12:48:16
3	2	1	DeWalt Powershift 12-inch Cut-Off Saw - Contractor Supply Magazine.pdf	workgroup-files/01KJQQ65XK2ZGST3J7Q9D4P3K5.pdf	pdf	\N	3	2026-03-02 16:48:01	2026-03-02 16:48:01
4	2	1	v-strut.pdf	workgroup-files/01KJQQKQMJN50EENCBTCR6BNQ4.pdf	pdf	\N	3	2026-03-02 16:55:25	2026-03-02 16:55:25
5	2	3	MBFD_work_group_day1.pdf	workgroup-files/01KJXSMW58D22WKM8KW3Q6G9QV.pdf	pdf	\N	3	2026-03-05 01:26:26	2026-03-05 01:26:26
6	2	1	TNT_Storm_Surge_20V_Evaluation.pdf	workgroup-files/01KJXT3P7HXKXJJSXPS00VAWG1.pdf	pdf	\N	3	2026-03-05 01:34:32	2026-03-05 01:34:32
7	2	4	Holmatro Pentheon Series USA.PDF	workgroup-files/01KK6H6WGVPADDEX8T7GS4FN18.PDF	PDF	\N	3	2026-03-08 10:52:09	2026-03-08 10:52:09
8	2	4	Holmatro omnishore.pdf	workgroup-files/01KK6H7DC4JBBTZYNRYTY0PHVT.pdf	pdf	\N	3	2026-03-08 10:52:26	2026-03-08 10:52:26
9	2	4	holmatro-t1.pdf	workgroup-files/01KK6H7TBMWKV2HY702QAT8QQ4.pdf	pdf	\N	3	2026-03-08 10:52:40	2026-03-08 10:52:40
10	2	3	tool_study.pdf	workgroup-files/01KK6HBT85Z7G23ZGHWFJ9JC53.pdf	pdf	\N	3	2026-03-08 10:54:51	2026-03-08 10:55:32
11	2	3	SAVER Extrication Tools FGR_Final_508_0.pdf	workgroup-files/01KK6HCBV6BTSY9NTYS9HZJE92.pdf	pdf	\N	3	2026-03-08 10:55:09	2026-03-08 10:55:43
\.


--
-- Data for Name: workgroup_members; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.workgroup_members (id, workgroup_id, user_id, role, is_active, created_at, updated_at, count_evaluations) FROM stdin;
2	2	19	member	t	2026-03-02 12:51:08	2026-03-02 12:51:08	t
4	2	21	member	t	2026-03-03 11:18:51	2026-03-03 11:18:51	t
10	2	24	member	t	2026-03-05 15:06:44	2026-03-05 15:06:44	t
13	2	25	member	t	2026-03-05 15:18:34	2026-03-05 15:18:34	t
14	2	27	member	t	2026-03-05 15:52:31	2026-03-05 15:52:31	t
5	2	6	admin	t	2026-03-03 13:08:17	2026-03-06 00:44:41	f
6	2	2	admin	t	2026-03-03 13:48:21	2026-03-06 00:44:47	f
7	2	3	admin	t	2026-03-04 01:54:00	2026-03-06 00:44:48	f
3	2	20	admin	t	2026-03-02 18:02:55	2026-03-06 00:44:59	f
16	2	23	member	t	2026-03-06 13:23:10	2026-03-06 13:23:10	t
19	2	28	member	t	2026-03-07 23:21:16	2026-03-07 23:21:16	t
20	2	29	member	t	2026-03-07 23:21:16	2026-03-07 23:21:16	t
21	2	30	member	t	2026-03-07 23:21:16	2026-03-07 23:21:16	t
22	2	31	member	t	2026-03-07 23:21:16	2026-03-07 23:21:16	t
23	2	32	member	t	2026-03-07 23:21:16	2026-03-07 23:21:16	t
24	2	33	member	t	2026-03-07 23:21:16	2026-03-07 23:21:16	t
\.


--
-- Data for Name: workgroup_notes; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.workgroup_notes (id, workgroup_member_id, workgroup_session_id, title, content, created_at, updated_at, is_shared, shared_with_user_id) FROM stdin;
5	7	1	test	<p>test</p>	2026-03-09 12:46:20	2026-03-09 12:46:20	t	2
\.


--
-- Data for Name: workgroup_sessions; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.workgroup_sessions (id, workgroup_id, name, start_date, end_date, status, created_at, updated_at) FROM stdin;
1	2	Day 2	2026-03-05	2026-03-05	active	2026-03-02 12:48:05	2026-03-02 12:48:05
3	2	Day 1	2026-02-23	2026-02-26	completed	2026-03-05 01:25:51	2026-03-05 01:25:51
4	2	Day 3	2026-03-09	2026-03-09	active	2026-03-08 10:47:52	2026-03-08 10:47:52
\.


--
-- Data for Name: workgroup_shared_uploads; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.workgroup_shared_uploads (id, workgroup_id, workgroup_session_id, user_id, workgroup_member_id, filename, filepath, file_type, file_size, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: workgroups; Type: TABLE DATA; Schema: public; Owner: mbfd_user
--

COPY public.workgroups (id, name, description, is_active, created_by, created_at, updated_at) FROM stdin;
2	Mid-Mount Ladder Work Group	Demo and evaluate lose item equipment for the new Mid-Mount Ladder truck.	t	3	2026-03-02 12:38:16	2026-03-02 12:38:16
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

SELECT pg_catalog.setval('public.apparatuses_id_seq', 63, true);


--
-- Name: big_ticket_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.big_ticket_requests_id_seq', 1, false);


--
-- Name: candidate_products_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.candidate_products_id_seq', 10, true);


--
-- Name: capital_projects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.capital_projects_id_seq', 48, true);


--
-- Name: equipment_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.equipment_items_id_seq', 192, true);


--
-- Name: evaluation_categories_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.evaluation_categories_id_seq', 3, true);


--
-- Name: evaluation_comments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.evaluation_comments_id_seq', 1, false);


--
-- Name: evaluation_criteria_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.evaluation_criteria_id_seq', 1, false);


--
-- Name: evaluation_scores_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.evaluation_scores_id_seq', 1, false);


--
-- Name: evaluation_submissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.evaluation_submissions_id_seq', 35, true);


--
-- Name: evaluation_templates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.evaluation_templates_id_seq', 1, false);


--
-- Name: external_nav_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.external_nav_items_id_seq', 1, false);


--
-- Name: external_sources_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.external_sources_id_seq', 1, false);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 3, true);


--
-- Name: import_runs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.import_runs_id_seq', 1, false);


--
-- Name: inventory_categories_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.inventory_categories_id_seq', 1, false);


--
-- Name: inventory_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.inventory_items_id_seq', 1, false);


--
-- Name: inventory_locations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.inventory_locations_id_seq', 47, true);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.jobs_id_seq', 46, true);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.migrations_id_seq', 118, true);


--
-- Name: notification_tracking_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.notification_tracking_id_seq', 1, false);


--
-- Name: permissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.permissions_id_seq', 206, true);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.personal_access_tokens_id_seq', 1, false);


--
-- Name: project_milestones_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.project_milestones_id_seq', 60, true);


--
-- Name: project_updates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.project_updates_id_seq', 1, true);


--
-- Name: push_subscriptions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.push_subscriptions_id_seq', 8, true);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.roles_id_seq', 8, true);


--
-- Name: room_assets_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.room_assets_id_seq', 1, false);


--
-- Name: room_audit_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.room_audit_items_id_seq', 1, false);


--
-- Name: room_audits_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.room_audits_id_seq', 1, false);


--
-- Name: rooms_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.rooms_id_seq', 1, false);


--
-- Name: session_user_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.session_user_id_seq', 12, true);


--
-- Name: session_workgroup_member_attendance_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.session_workgroup_member_attendance_id_seq', 30, true);


--
-- Name: shop_works_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.shop_works_id_seq', 16, true);


--
-- Name: single_gas_meters_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.single_gas_meters_id_seq', 13, true);


--
-- Name: station_inventory_audits_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.station_inventory_audits_id_seq', 1, false);


--
-- Name: station_inventory_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.station_inventory_items_id_seq', 1, false);


--
-- Name: station_inventory_submissions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.station_inventory_submissions_id_seq', 2, true);


--
-- Name: station_supply_requests_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.station_supply_requests_id_seq', 1, false);


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

SELECT pg_catalog.setval('public.todo_updates_id_seq', 11, true);


--
-- Name: todos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.todos_id_seq', 18, true);


--
-- Name: training_todo_updates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.training_todo_updates_id_seq', 1, false);


--
-- Name: training_todos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.training_todos_id_seq', 1, false);


--
-- Name: under_25k_project_updates_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.under_25k_project_updates_id_seq', 1, false);


--
-- Name: under_25k_projects_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.under_25k_projects_id_seq', 84, true);


--
-- Name: uniforms_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.uniforms_id_seq', 1, false);


--
-- Name: unit_master_vehicles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.unit_master_vehicles_id_seq', 89, true);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.users_id_seq', 33, true);


--
-- Name: workgroup_files_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.workgroup_files_id_seq', 11, true);


--
-- Name: workgroup_members_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.workgroup_members_id_seq', 24, true);


--
-- Name: workgroup_notes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.workgroup_notes_id_seq', 5, true);


--
-- Name: workgroup_sessions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.workgroup_sessions_id_seq', 4, true);


--
-- Name: workgroup_shared_uploads_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.workgroup_shared_uploads_id_seq', 1, false);


--
-- Name: workgroups_id_seq; Type: SEQUENCE SET; Schema: public; Owner: mbfd_user
--

SELECT pg_catalog.setval('public.workgroups_id_seq', 3, true);


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
-- Name: session_workgroup_member_attendance attendance_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.session_workgroup_member_attendance
    ADD CONSTRAINT attendance_unique UNIQUE (workgroup_session_id, workgroup_member_id);


--
-- Name: big_ticket_requests big_ticket_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.big_ticket_requests
    ADD CONSTRAINT big_ticket_requests_pkey PRIMARY KEY (id);


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
-- Name: candidate_products candidate_products_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.candidate_products
    ADD CONSTRAINT candidate_products_pkey PRIMARY KEY (id);


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
-- Name: ch_favorites ch_favorites_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.ch_favorites
    ADD CONSTRAINT ch_favorites_pkey PRIMARY KEY (id);


--
-- Name: ch_messages ch_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.ch_messages
    ADD CONSTRAINT ch_messages_pkey PRIMARY KEY (id);


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
-- Name: evaluation_submissions eval_sub_user_product_session_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_submissions
    ADD CONSTRAINT eval_sub_user_product_session_unique UNIQUE (user_id, candidate_product_id, session_id);


--
-- Name: evaluation_categories evaluation_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_categories
    ADD CONSTRAINT evaluation_categories_pkey PRIMARY KEY (id);


--
-- Name: evaluation_comments evaluation_comments_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_comments
    ADD CONSTRAINT evaluation_comments_pkey PRIMARY KEY (id);


--
-- Name: evaluation_criteria evaluation_criteria_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_criteria
    ADD CONSTRAINT evaluation_criteria_pkey PRIMARY KEY (id);


--
-- Name: evaluation_scores evaluation_scores_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_scores
    ADD CONSTRAINT evaluation_scores_pkey PRIMARY KEY (id);


--
-- Name: evaluation_scores evaluation_scores_submission_id_criterion_id_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_scores
    ADD CONSTRAINT evaluation_scores_submission_id_criterion_id_unique UNIQUE (submission_id, criterion_id);


--
-- Name: evaluation_submissions evaluation_submissions_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_submissions
    ADD CONSTRAINT evaluation_submissions_pkey PRIMARY KEY (id);


--
-- Name: evaluation_submissions evaluation_submissions_workgroup_member_id_candidate_product_id; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_submissions
    ADD CONSTRAINT evaluation_submissions_workgroup_member_id_candidate_product_id UNIQUE (workgroup_member_id, candidate_product_id);


--
-- Name: evaluation_templates evaluation_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_templates
    ADD CONSTRAINT evaluation_templates_pkey PRIMARY KEY (id);


--
-- Name: external_nav_items external_nav_items_division_slug_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.external_nav_items
    ADD CONSTRAINT external_nav_items_division_slug_unique UNIQUE (division, slug);


--
-- Name: external_nav_items external_nav_items_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.external_nav_items
    ADD CONSTRAINT external_nav_items_pkey PRIMARY KEY (id);


--
-- Name: external_sources external_sources_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.external_sources
    ADD CONSTRAINT external_sources_pkey PRIMARY KEY (id);


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
-- Name: inventory_categories inventory_categories_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.inventory_categories
    ADD CONSTRAINT inventory_categories_pkey PRIMARY KEY (id);


--
-- Name: inventory_items inventory_items_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.inventory_items
    ADD CONSTRAINT inventory_items_pkey PRIMARY KEY (id);


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
-- Name: room_assets room_assets_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_assets
    ADD CONSTRAINT room_assets_pkey PRIMARY KEY (id);


--
-- Name: room_audit_items room_audit_items_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_audit_items
    ADD CONSTRAINT room_audit_items_pkey PRIMARY KEY (id);


--
-- Name: room_audits room_audits_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_audits
    ADD CONSTRAINT room_audits_pkey PRIMARY KEY (id);


--
-- Name: rooms rooms_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_pkey PRIMARY KEY (id);


--
-- Name: session_user session_user_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public."session_user"
    ADD CONSTRAINT session_user_pkey PRIMARY KEY (id);


--
-- Name: session_user session_user_workgroup_session_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public."session_user"
    ADD CONSTRAINT session_user_workgroup_session_id_user_id_unique UNIQUE (workgroup_session_id, user_id);


--
-- Name: session_workgroup_member_attendance session_workgroup_member_attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.session_workgroup_member_attendance
    ADD CONSTRAINT session_workgroup_member_attendance_pkey PRIMARY KEY (id);


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
-- Name: single_gas_meters single_gas_meters_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.single_gas_meters
    ADD CONSTRAINT single_gas_meters_pkey PRIMARY KEY (id);


--
-- Name: single_gas_meters single_gas_meters_serial_number_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.single_gas_meters
    ADD CONSTRAINT single_gas_meters_serial_number_unique UNIQUE (serial_number);


--
-- Name: station_inventory_audits station_inventory_audits_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_audits
    ADD CONSTRAINT station_inventory_audits_pkey PRIMARY KEY (id);


--
-- Name: station_inventory_items station_inventory_items_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_items
    ADD CONSTRAINT station_inventory_items_pkey PRIMARY KEY (id);


--
-- Name: station_inventory_items station_inventory_items_station_id_inventory_item_id_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_items
    ADD CONSTRAINT station_inventory_items_station_id_inventory_item_id_unique UNIQUE (station_id, inventory_item_id);


--
-- Name: station_inventory_submissions station_inventory_submissions_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_submissions
    ADD CONSTRAINT station_inventory_submissions_pkey PRIMARY KEY (id);


--
-- Name: station_supply_requests station_supply_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_supply_requests
    ADD CONSTRAINT station_supply_requests_pkey PRIMARY KEY (id);


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
-- Name: training_todo_updates training_todo_updates_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.training_todo_updates
    ADD CONSTRAINT training_todo_updates_pkey PRIMARY KEY (id);


--
-- Name: training_todos training_todos_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.training_todos
    ADD CONSTRAINT training_todos_pkey PRIMARY KEY (id);


--
-- Name: under_25k_project_updates under_25k_project_updates_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.under_25k_project_updates
    ADD CONSTRAINT under_25k_project_updates_pkey PRIMARY KEY (id);


--
-- Name: under_25k_projects under_25k_projects_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.under_25k_projects
    ADD CONSTRAINT under_25k_projects_pkey PRIMARY KEY (id);


--
-- Name: uniforms uniforms_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.uniforms
    ADD CONSTRAINT uniforms_pkey PRIMARY KEY (id);


--
-- Name: unit_master_vehicles unit_master_vehicles_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.unit_master_vehicles
    ADD CONSTRAINT unit_master_vehicles_pkey PRIMARY KEY (id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: workgroup_files workgroup_files_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_files
    ADD CONSTRAINT workgroup_files_pkey PRIMARY KEY (id);


--
-- Name: workgroup_members workgroup_members_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_members
    ADD CONSTRAINT workgroup_members_pkey PRIMARY KEY (id);


--
-- Name: workgroup_members workgroup_members_workgroup_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_members
    ADD CONSTRAINT workgroup_members_workgroup_id_user_id_unique UNIQUE (workgroup_id, user_id);


--
-- Name: workgroup_notes workgroup_notes_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_notes
    ADD CONSTRAINT workgroup_notes_pkey PRIMARY KEY (id);


--
-- Name: workgroup_sessions workgroup_sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_sessions
    ADD CONSTRAINT workgroup_sessions_pkey PRIMARY KEY (id);


--
-- Name: workgroup_shared_uploads workgroup_shared_uploads_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_shared_uploads
    ADD CONSTRAINT workgroup_shared_uploads_pkey PRIMARY KEY (id);


--
-- Name: workgroups workgroups_pkey; Type: CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroups
    ADD CONSTRAINT workgroups_pkey PRIMARY KEY (id);


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
-- Name: candidate_products_category_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX candidate_products_category_id_index ON public.candidate_products USING btree (category_id);


--
-- Name: candidate_products_workgroup_session_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX candidate_products_workgroup_session_id_index ON public.candidate_products USING btree (workgroup_session_id);


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
-- Name: evaluation_categories_display_order_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_categories_display_order_index ON public.evaluation_categories USING btree (display_order);


--
-- Name: evaluation_categories_is_active_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_categories_is_active_index ON public.evaluation_categories USING btree (is_active);


--
-- Name: evaluation_comments_category_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_comments_category_id_index ON public.evaluation_comments USING btree (category_id);


--
-- Name: evaluation_comments_submission_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_comments_submission_id_index ON public.evaluation_comments USING btree (submission_id);


--
-- Name: evaluation_criteria_display_order_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_criteria_display_order_index ON public.evaluation_criteria USING btree (display_order);


--
-- Name: evaluation_criteria_template_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_criteria_template_id_index ON public.evaluation_criteria USING btree (template_id);


--
-- Name: evaluation_submissions_advance_recommendation_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_submissions_advance_recommendation_index ON public.evaluation_submissions USING btree (advance_recommendation);


--
-- Name: evaluation_submissions_is_locked_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_submissions_is_locked_index ON public.evaluation_submissions USING btree (is_locked);


--
-- Name: evaluation_submissions_overall_score_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_submissions_overall_score_index ON public.evaluation_submissions USING btree (overall_score);


--
-- Name: evaluation_submissions_session_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_submissions_session_id_index ON public.evaluation_submissions USING btree (session_id);


--
-- Name: evaluation_submissions_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_submissions_status_index ON public.evaluation_submissions USING btree (status);


--
-- Name: evaluation_templates_category_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_templates_category_id_index ON public.evaluation_templates USING btree (category_id);


--
-- Name: evaluation_templates_is_active_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX evaluation_templates_is_active_index ON public.evaluation_templates USING btree (is_active);


--
-- Name: external_nav_items_division_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX external_nav_items_division_index ON public.external_nav_items USING btree (division);


--
-- Name: external_sources_division_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX external_sources_division_index ON public.external_sources USING btree (division);


--
-- Name: import_runs_started_at_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX import_runs_started_at_index ON public.import_runs USING btree (started_at);


--
-- Name: import_runs_type_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX import_runs_type_index ON public.import_runs USING btree (type);


--
-- Name: inventory_categories_active_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX inventory_categories_active_index ON public.inventory_categories USING btree (active);


--
-- Name: inventory_categories_sort_order_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX inventory_categories_sort_order_index ON public.inventory_categories USING btree (sort_order);


--
-- Name: inventory_items_active_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX inventory_items_active_index ON public.inventory_items USING btree (active);


--
-- Name: inventory_items_category_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX inventory_items_category_id_index ON public.inventory_items USING btree (category_id);


--
-- Name: inventory_items_category_id_sort_order_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX inventory_items_category_id_sort_order_index ON public.inventory_items USING btree (category_id, sort_order);


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
-- Name: room_assets_room_id_category_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX room_assets_room_id_category_index ON public.room_assets USING btree (room_id, category);


--
-- Name: room_assets_room_id_condition_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX room_assets_room_id_condition_index ON public.room_assets USING btree (room_id, condition);


--
-- Name: room_audit_items_room_audit_id_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX room_audit_items_room_audit_id_status_index ON public.room_audit_items USING btree (room_audit_id, status);


--
-- Name: room_audits_room_id_audit_date_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX room_audits_room_id_audit_date_index ON public.room_audits USING btree (room_id, audit_date);


--
-- Name: room_audits_room_id_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX room_audits_room_id_status_index ON public.room_audits USING btree (room_id, status);


--
-- Name: rooms_station_id_name_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX rooms_station_id_name_index ON public.rooms USING btree (station_id, name);


--
-- Name: rooms_station_id_type_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX rooms_station_id_type_index ON public.rooms USING btree (station_id, type);


--
-- Name: session_user_is_official_evaluator_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX session_user_is_official_evaluator_index ON public."session_user" USING btree (is_official_evaluator);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: station_inventory_audits_inventory_item_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX station_inventory_audits_inventory_item_id_index ON public.station_inventory_audits USING btree (inventory_item_id);


--
-- Name: station_inventory_audits_station_id_created_at_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX station_inventory_audits_station_id_created_at_index ON public.station_inventory_audits USING btree (station_id, created_at);


--
-- Name: station_inventory_audits_station_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX station_inventory_audits_station_id_index ON public.station_inventory_audits USING btree (station_id);


--
-- Name: station_inventory_items_station_id_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX station_inventory_items_station_id_status_index ON public.station_inventory_items USING btree (station_id, status);


--
-- Name: station_inventory_items_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX station_inventory_items_status_index ON public.station_inventory_items USING btree (status);


--
-- Name: station_supply_requests_station_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX station_supply_requests_station_id_index ON public.station_supply_requests USING btree (station_id);


--
-- Name: station_supply_requests_station_id_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX station_supply_requests_station_id_status_index ON public.station_supply_requests USING btree (station_id, status);


--
-- Name: station_supply_requests_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX station_supply_requests_status_index ON public.station_supply_requests USING btree (status);


--
-- Name: stock_mutations_stocker_type_stocker_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX stock_mutations_stocker_type_stocker_id_index ON public.stock_mutations USING btree (stockable_type, stockable_id);


--
-- Name: under_25k_project_updates_under_25k_project_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX under_25k_project_updates_under_25k_project_id_index ON public.under_25k_project_updates USING btree (under_25k_project_id);


--
-- Name: under_25k_project_updates_user_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX under_25k_project_updates_user_id_index ON public.under_25k_project_updates USING btree (user_id);


--
-- Name: under_25k_projects_priority_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX under_25k_projects_priority_index ON public.under_25k_projects USING btree (priority);


--
-- Name: under_25k_projects_project_number_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX under_25k_projects_project_number_index ON public.under_25k_projects USING btree (project_number);


--
-- Name: under_25k_projects_start_date_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX under_25k_projects_start_date_index ON public.under_25k_projects USING btree (start_date);


--
-- Name: under_25k_projects_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX under_25k_projects_status_index ON public.under_25k_projects USING btree (status);


--
-- Name: under_25k_projects_target_completion_date_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX under_25k_projects_target_completion_date_index ON public.under_25k_projects USING btree (target_completion_date);


--
-- Name: unit_master_vehicles_dept_code_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX unit_master_vehicles_dept_code_index ON public.unit_master_vehicles USING btree (dept_code);


--
-- Name: unit_master_vehicles_section_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX unit_master_vehicles_section_index ON public.unit_master_vehicles USING btree (section);


--
-- Name: unit_master_vehicles_serial_number_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX unit_master_vehicles_serial_number_index ON public.unit_master_vehicles USING btree (serial_number);


--
-- Name: unit_master_vehicles_tag_number_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX unit_master_vehicles_tag_number_index ON public.unit_master_vehicles USING btree (tag_number);


--
-- Name: unit_master_vehicles_veh_number_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX unit_master_vehicles_veh_number_index ON public.unit_master_vehicles USING btree (veh_number);


--
-- Name: users_email_ci_unique; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE UNIQUE INDEX users_email_ci_unique ON public.users USING btree (lower((email)::text));


--
-- Name: workgroup_files_uploaded_by_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_files_uploaded_by_index ON public.workgroup_files USING btree (uploaded_by);


--
-- Name: workgroup_files_workgroup_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_files_workgroup_id_index ON public.workgroup_files USING btree (workgroup_id);


--
-- Name: workgroup_files_workgroup_session_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_files_workgroup_session_id_index ON public.workgroup_files USING btree (workgroup_session_id);


--
-- Name: workgroup_members_is_active_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_members_is_active_index ON public.workgroup_members USING btree (is_active);


--
-- Name: workgroup_members_role_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_members_role_index ON public.workgroup_members USING btree (role);


--
-- Name: workgroup_notes_is_shared_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_notes_is_shared_index ON public.workgroup_notes USING btree (is_shared);


--
-- Name: workgroup_notes_shared_with_user_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_notes_shared_with_user_id_index ON public.workgroup_notes USING btree (shared_with_user_id);


--
-- Name: workgroup_notes_workgroup_member_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_notes_workgroup_member_id_index ON public.workgroup_notes USING btree (workgroup_member_id);


--
-- Name: workgroup_notes_workgroup_session_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_notes_workgroup_session_id_index ON public.workgroup_notes USING btree (workgroup_session_id);


--
-- Name: workgroup_sessions_status_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_sessions_status_index ON public.workgroup_sessions USING btree (status);


--
-- Name: workgroup_sessions_workgroup_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_sessions_workgroup_id_index ON public.workgroup_sessions USING btree (workgroup_id);


--
-- Name: workgroup_shared_uploads_user_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_shared_uploads_user_id_index ON public.workgroup_shared_uploads USING btree (user_id);


--
-- Name: workgroup_shared_uploads_workgroup_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_shared_uploads_workgroup_id_index ON public.workgroup_shared_uploads USING btree (workgroup_id);


--
-- Name: workgroup_shared_uploads_workgroup_member_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_shared_uploads_workgroup_member_id_index ON public.workgroup_shared_uploads USING btree (workgroup_member_id);


--
-- Name: workgroup_shared_uploads_workgroup_session_id_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroup_shared_uploads_workgroup_session_id_index ON public.workgroup_shared_uploads USING btree (workgroup_session_id);


--
-- Name: workgroups_created_by_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroups_created_by_index ON public.workgroups USING btree (created_by);


--
-- Name: workgroups_is_active_index; Type: INDEX; Schema: public; Owner: mbfd_user
--

CREATE INDEX workgroups_is_active_index ON public.workgroups USING btree (is_active);


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
-- Name: apparatuses apparatuses_station_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.apparatuses
    ADD CONSTRAINT apparatuses_station_id_foreign FOREIGN KEY (station_id) REFERENCES public.stations(id) ON DELETE SET NULL;


--
-- Name: big_ticket_requests big_ticket_requests_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.big_ticket_requests
    ADD CONSTRAINT big_ticket_requests_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: big_ticket_requests big_ticket_requests_station_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.big_ticket_requests
    ADD CONSTRAINT big_ticket_requests_station_id_foreign FOREIGN KEY (station_id) REFERENCES public.stations(id);


--
-- Name: candidate_products candidate_products_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.candidate_products
    ADD CONSTRAINT candidate_products_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.evaluation_categories(id) ON DELETE CASCADE;


--
-- Name: candidate_products candidate_products_workgroup_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.candidate_products
    ADD CONSTRAINT candidate_products_workgroup_session_id_foreign FOREIGN KEY (workgroup_session_id) REFERENCES public.workgroup_sessions(id) ON DELETE CASCADE;


--
-- Name: capital_projects capital_projects_station_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.capital_projects
    ADD CONSTRAINT capital_projects_station_id_foreign FOREIGN KEY (station_id) REFERENCES public.stations(id) ON DELETE SET NULL;


--
-- Name: equipment_items equipment_items_location_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.equipment_items
    ADD CONSTRAINT equipment_items_location_id_foreign FOREIGN KEY (location_id) REFERENCES public.inventory_locations(id) ON DELETE SET NULL;


--
-- Name: evaluation_comments evaluation_comments_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_comments
    ADD CONSTRAINT evaluation_comments_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.evaluation_categories(id) ON DELETE SET NULL;


--
-- Name: evaluation_comments evaluation_comments_submission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_comments
    ADD CONSTRAINT evaluation_comments_submission_id_foreign FOREIGN KEY (submission_id) REFERENCES public.evaluation_submissions(id) ON DELETE CASCADE;


--
-- Name: evaluation_criteria evaluation_criteria_template_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_criteria
    ADD CONSTRAINT evaluation_criteria_template_id_foreign FOREIGN KEY (template_id) REFERENCES public.evaluation_templates(id) ON DELETE CASCADE;


--
-- Name: evaluation_scores evaluation_scores_criterion_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_scores
    ADD CONSTRAINT evaluation_scores_criterion_id_foreign FOREIGN KEY (criterion_id) REFERENCES public.evaluation_criteria(id) ON DELETE CASCADE;


--
-- Name: evaluation_scores evaluation_scores_submission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_scores
    ADD CONSTRAINT evaluation_scores_submission_id_foreign FOREIGN KEY (submission_id) REFERENCES public.evaluation_submissions(id) ON DELETE CASCADE;


--
-- Name: evaluation_submissions evaluation_submissions_candidate_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_submissions
    ADD CONSTRAINT evaluation_submissions_candidate_product_id_foreign FOREIGN KEY (candidate_product_id) REFERENCES public.candidate_products(id) ON DELETE CASCADE;


--
-- Name: evaluation_submissions evaluation_submissions_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_submissions
    ADD CONSTRAINT evaluation_submissions_session_id_foreign FOREIGN KEY (session_id) REFERENCES public.workgroup_sessions(id) ON DELETE SET NULL;


--
-- Name: evaluation_submissions evaluation_submissions_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_submissions
    ADD CONSTRAINT evaluation_submissions_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: evaluation_submissions evaluation_submissions_workgroup_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_submissions
    ADD CONSTRAINT evaluation_submissions_workgroup_member_id_foreign FOREIGN KEY (workgroup_member_id) REFERENCES public.workgroup_members(id) ON DELETE CASCADE;


--
-- Name: evaluation_templates evaluation_templates_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.evaluation_templates
    ADD CONSTRAINT evaluation_templates_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.evaluation_categories(id) ON DELETE CASCADE;


--
-- Name: external_nav_items external_nav_items_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.external_nav_items
    ADD CONSTRAINT external_nav_items_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: external_nav_items external_nav_items_external_source_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.external_nav_items
    ADD CONSTRAINT external_nav_items_external_source_id_foreign FOREIGN KEY (external_source_id) REFERENCES public.external_sources(id) ON DELETE SET NULL;


--
-- Name: external_sources external_sources_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.external_sources
    ADD CONSTRAINT external_sources_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: import_runs import_runs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.import_runs
    ADD CONSTRAINT import_runs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: inventory_items inventory_items_category_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.inventory_items
    ADD CONSTRAINT inventory_items_category_id_foreign FOREIGN KEY (category_id) REFERENCES public.inventory_categories(id) ON DELETE CASCADE;


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
-- Name: room_assets room_assets_room_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_assets
    ADD CONSTRAINT room_assets_room_id_foreign FOREIGN KEY (room_id) REFERENCES public.rooms(id) ON DELETE CASCADE;


--
-- Name: room_audit_items room_audit_items_room_asset_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_audit_items
    ADD CONSTRAINT room_audit_items_room_asset_id_foreign FOREIGN KEY (room_asset_id) REFERENCES public.room_assets(id) ON DELETE SET NULL;


--
-- Name: room_audit_items room_audit_items_room_audit_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_audit_items
    ADD CONSTRAINT room_audit_items_room_audit_id_foreign FOREIGN KEY (room_audit_id) REFERENCES public.room_audits(id) ON DELETE CASCADE;


--
-- Name: room_audits room_audits_room_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_audits
    ADD CONSTRAINT room_audits_room_id_foreign FOREIGN KEY (room_id) REFERENCES public.rooms(id) ON DELETE CASCADE;


--
-- Name: room_audits room_audits_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.room_audits
    ADD CONSTRAINT room_audits_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: rooms rooms_station_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_station_id_foreign FOREIGN KEY (station_id) REFERENCES public.stations(id) ON DELETE CASCADE;


--
-- Name: session_user session_user_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public."session_user"
    ADD CONSTRAINT session_user_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: session_user session_user_workgroup_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public."session_user"
    ADD CONSTRAINT session_user_workgroup_session_id_foreign FOREIGN KEY (workgroup_session_id) REFERENCES public.workgroup_sessions(id) ON DELETE CASCADE;


--
-- Name: session_workgroup_member_attendance session_workgroup_member_attendance_workgroup_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.session_workgroup_member_attendance
    ADD CONSTRAINT session_workgroup_member_attendance_workgroup_member_id_foreign FOREIGN KEY (workgroup_member_id) REFERENCES public.workgroup_members(id) ON DELETE CASCADE;


--
-- Name: session_workgroup_member_attendance session_workgroup_member_attendance_workgroup_session_id_foreig; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.session_workgroup_member_attendance
    ADD CONSTRAINT session_workgroup_member_attendance_workgroup_session_id_foreig FOREIGN KEY (workgroup_session_id) REFERENCES public.workgroup_sessions(id) ON DELETE CASCADE;


--
-- Name: shop_works shop_works_apparatus_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.shop_works
    ADD CONSTRAINT shop_works_apparatus_id_foreign FOREIGN KEY (apparatus_id) REFERENCES public.apparatuses(id) ON DELETE SET NULL;


--
-- Name: single_gas_meters single_gas_meters_apparatus_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.single_gas_meters
    ADD CONSTRAINT single_gas_meters_apparatus_id_foreign FOREIGN KEY (apparatus_id) REFERENCES public.apparatuses(id) ON DELETE CASCADE;


--
-- Name: station_inventory_audits station_inventory_audits_inventory_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_audits
    ADD CONSTRAINT station_inventory_audits_inventory_item_id_foreign FOREIGN KEY (inventory_item_id) REFERENCES public.inventory_items(id) ON DELETE SET NULL;


--
-- Name: station_inventory_audits station_inventory_audits_station_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_audits
    ADD CONSTRAINT station_inventory_audits_station_id_foreign FOREIGN KEY (station_id) REFERENCES public.stations(id) ON DELETE CASCADE;


--
-- Name: station_inventory_items station_inventory_items_inventory_item_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_items
    ADD CONSTRAINT station_inventory_items_inventory_item_id_foreign FOREIGN KEY (inventory_item_id) REFERENCES public.inventory_items(id) ON DELETE CASCADE;


--
-- Name: station_inventory_items station_inventory_items_station_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_items
    ADD CONSTRAINT station_inventory_items_station_id_foreign FOREIGN KEY (station_id) REFERENCES public.stations(id) ON DELETE CASCADE;


--
-- Name: station_inventory_submissions station_inventory_submissions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_submissions
    ADD CONSTRAINT station_inventory_submissions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: station_inventory_submissions station_inventory_submissions_station_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_inventory_submissions
    ADD CONSTRAINT station_inventory_submissions_station_id_foreign FOREIGN KEY (station_id) REFERENCES public.stations(id);


--
-- Name: station_supply_requests station_supply_requests_station_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.station_supply_requests
    ADD CONSTRAINT station_supply_requests_station_id_foreign FOREIGN KEY (station_id) REFERENCES public.stations(id) ON DELETE CASCADE;


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
-- Name: training_todo_updates training_todo_updates_training_todo_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.training_todo_updates
    ADD CONSTRAINT training_todo_updates_training_todo_id_foreign FOREIGN KEY (training_todo_id) REFERENCES public.training_todos(id) ON DELETE CASCADE;


--
-- Name: training_todo_updates training_todo_updates_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.training_todo_updates
    ADD CONSTRAINT training_todo_updates_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: training_todos training_todos_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.training_todos
    ADD CONSTRAINT training_todos_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: under_25k_project_updates under_25k_project_updates_under_25k_project_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.under_25k_project_updates
    ADD CONSTRAINT under_25k_project_updates_under_25k_project_id_foreign FOREIGN KEY (under_25k_project_id) REFERENCES public.under_25k_projects(id) ON DELETE CASCADE;


--
-- Name: under_25k_project_updates under_25k_project_updates_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.under_25k_project_updates
    ADD CONSTRAINT under_25k_project_updates_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: under_25k_projects under_25k_projects_station_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.under_25k_projects
    ADD CONSTRAINT under_25k_projects_station_id_foreign FOREIGN KEY (station_id) REFERENCES public.stations(id) ON DELETE SET NULL;


--
-- Name: workgroup_files workgroup_files_uploaded_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_files
    ADD CONSTRAINT workgroup_files_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: workgroup_files workgroup_files_workgroup_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_files
    ADD CONSTRAINT workgroup_files_workgroup_id_foreign FOREIGN KEY (workgroup_id) REFERENCES public.workgroups(id) ON DELETE CASCADE;


--
-- Name: workgroup_files workgroup_files_workgroup_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_files
    ADD CONSTRAINT workgroup_files_workgroup_session_id_foreign FOREIGN KEY (workgroup_session_id) REFERENCES public.workgroup_sessions(id) ON DELETE SET NULL;


--
-- Name: workgroup_members workgroup_members_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_members
    ADD CONSTRAINT workgroup_members_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: workgroup_members workgroup_members_workgroup_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_members
    ADD CONSTRAINT workgroup_members_workgroup_id_foreign FOREIGN KEY (workgroup_id) REFERENCES public.workgroups(id) ON DELETE CASCADE;


--
-- Name: workgroup_notes workgroup_notes_shared_with_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_notes
    ADD CONSTRAINT workgroup_notes_shared_with_user_id_foreign FOREIGN KEY (shared_with_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: workgroup_notes workgroup_notes_workgroup_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_notes
    ADD CONSTRAINT workgroup_notes_workgroup_member_id_foreign FOREIGN KEY (workgroup_member_id) REFERENCES public.workgroup_members(id) ON DELETE CASCADE;


--
-- Name: workgroup_notes workgroup_notes_workgroup_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_notes
    ADD CONSTRAINT workgroup_notes_workgroup_session_id_foreign FOREIGN KEY (workgroup_session_id) REFERENCES public.workgroup_sessions(id) ON DELETE SET NULL;


--
-- Name: workgroup_sessions workgroup_sessions_workgroup_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_sessions
    ADD CONSTRAINT workgroup_sessions_workgroup_id_foreign FOREIGN KEY (workgroup_id) REFERENCES public.workgroups(id) ON DELETE CASCADE;


--
-- Name: workgroup_shared_uploads workgroup_shared_uploads_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_shared_uploads
    ADD CONSTRAINT workgroup_shared_uploads_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: workgroup_shared_uploads workgroup_shared_uploads_workgroup_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_shared_uploads
    ADD CONSTRAINT workgroup_shared_uploads_workgroup_id_foreign FOREIGN KEY (workgroup_id) REFERENCES public.workgroups(id) ON DELETE CASCADE;


--
-- Name: workgroup_shared_uploads workgroup_shared_uploads_workgroup_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_shared_uploads
    ADD CONSTRAINT workgroup_shared_uploads_workgroup_member_id_foreign FOREIGN KEY (workgroup_member_id) REFERENCES public.workgroup_members(id) ON DELETE CASCADE;


--
-- Name: workgroup_shared_uploads workgroup_shared_uploads_workgroup_session_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroup_shared_uploads
    ADD CONSTRAINT workgroup_shared_uploads_workgroup_session_id_foreign FOREIGN KEY (workgroup_session_id) REFERENCES public.workgroup_sessions(id) ON DELETE CASCADE;


--
-- Name: workgroups workgroups_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: mbfd_user
--

ALTER TABLE ONLY public.workgroups
    ADD CONSTRAINT workgroups_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- PostgreSQL database dump complete
--

\unrestrict W31CExT3Fkl0a9wK7yMa9eTfsmPh7ax7hKgjOqOyKHckWzFCy4sxHVl8tkM3as2

