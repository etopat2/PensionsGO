-- Public Live Chat / Public Correspondence & Live Support Module
-- Apply after database/schema.sql.

CREATE TABLE IF NOT EXISTS public_chat_sessions (
    session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chat_reference VARCHAR(40) NOT NULL,
    visitor_name VARCHAR(160) NOT NULL,
    phone_number VARCHAR(50) DEFAULT NULL,
    email VARCHAR(160) DEFAULT NULL,
    force_number VARCHAR(80) DEFAULT NULL,
    pensioner_number VARCHAR(80) DEFAULT NULL,
    district VARCHAR(120) NOT NULL,
    location VARCHAR(180) DEFAULT NULL,
    inquiry_category VARCHAR(80) NOT NULL,
    subject VARCHAR(220) DEFAULT NULL,
    consent_accepted TINYINT(1) NOT NULL DEFAULT 0,
    source_page VARCHAR(220) DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    status ENUM('waiting','active','assigned','escalated','closed') NOT NULL DEFAULT 'waiting',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    assigned_agent_id VARCHAR(100) DEFAULT NULL,
    pensioner_user_id VARCHAR(100) DEFAULT NULL,
    pensioner_context_json LONGTEXT DEFAULT NULL,
    outcome VARCHAR(120) DEFAULT NULL,
    first_response_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    closed_by VARCHAR(100) DEFAULT NULL,
    close_reason VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (session_id),
    UNIQUE KEY uniq_public_chat_reference (chat_reference),
    KEY idx_public_chat_status (status),
    KEY idx_public_chat_created_at (created_at),
    KEY idx_public_chat_assigned_agent (assigned_agent_id),
    KEY idx_public_chat_phone (phone_number),
    KEY idx_public_chat_force (force_number),
    KEY idx_public_chat_pensioner (pensioner_number),
    KEY idx_public_chat_district (district),
    KEY idx_public_chat_category (inquiry_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_messages (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    sender_type ENUM('visitor','agent','system') NOT NULL DEFAULT 'visitor',
    sender_id VARCHAR(100) DEFAULT NULL,
    sender_name VARCHAR(160) DEFAULT NULL,
    message_text TEXT NOT NULL,
    message_kind ENUM('text','attachment','voice') NOT NULL DEFAULT 'text',
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    KEY idx_public_chat_messages_session (session_id, message_id),
    KEY idx_public_chat_messages_created (created_at),
    CONSTRAINT fk_public_chat_messages_session FOREIGN KEY (session_id) REFERENCES public_chat_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_typing (
    typing_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    actor_type ENUM('visitor','agent') NOT NULL,
    actor_id VARCHAR(100) NOT NULL DEFAULT '',
    actor_name VARCHAR(160) DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (typing_id),
    UNIQUE KEY uniq_public_chat_typing_actor (session_id, actor_type, actor_id),
    KEY idx_public_chat_typing_session (session_id, updated_at),
    CONSTRAINT fk_public_chat_typing_session FOREIGN KEY (session_id) REFERENCES public_chat_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_agents (
    agent_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(100) NOT NULL,
    agent_status ENUM('offline','available','busy','away') NOT NULL DEFAULT 'offline',
    availability_status ENUM('online','busy','away','offline') NOT NULL DEFAULT 'offline',
    can_handle_public_chat TINYINT(1) NOT NULL DEFAULT 1,
    can_accept_chat TINYINT(1) NOT NULL DEFAULT 1,
    can_transfer_chat TINYINT(1) NOT NULL DEFAULT 0,
    can_escalate_chat TINYINT(1) NOT NULL DEFAULT 0,
    can_close_chat TINYINT(1) NOT NULL DEFAULT 1,
    can_view_all_chats TINYINT(1) NOT NULL DEFAULT 0,
    can_view_reports TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_canned_responses TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_chat_settings TINYINT(1) NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    max_active_chats INT NOT NULL DEFAULT 5,
    last_seen_at DATETIME DEFAULT NULL,
    appointed_by VARCHAR(100) DEFAULT NULL,
    appointed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (agent_id),
    UNIQUE KEY uniq_public_chat_agent_user (user_id),
    KEY idx_public_chat_agent_status (agent_status, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_assignments (
    assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    agent_user_id VARCHAR(100) NOT NULL,
    assigned_by VARCHAR(100) DEFAULT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unassigned_at DATETIME DEFAULT NULL,
    assignment_status ENUM('active','ended') NOT NULL DEFAULT 'active',
    PRIMARY KEY (assignment_id),
    KEY idx_public_chat_assign_session (session_id, assignment_status),
    KEY idx_public_chat_assign_agent (agent_user_id, assignment_status),
    CONSTRAINT fk_public_chat_assign_session FOREIGN KEY (session_id) REFERENCES public_chat_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_attachments (
    attachment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED DEFAULT NULL,
    uploaded_by_type ENUM('visitor','agent') NOT NULL DEFAULT 'visitor',
    uploaded_by VARCHAR(100) DEFAULT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT DEFAULT NULL,
    mime_type VARCHAR(120) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attachment_id),
    KEY idx_public_chat_attach_session (session_id),
    KEY idx_public_chat_attach_message (message_id),
    CONSTRAINT fk_public_chat_attach_session FOREIGN KEY (session_id) REFERENCES public_chat_sessions(session_id) ON DELETE CASCADE,
    CONSTRAINT fk_public_chat_attach_message FOREIGN KEY (message_id) REFERENCES public_chat_messages(message_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_notes (
    note_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    agent_user_id VARCHAR(100) NOT NULL,
    note_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (note_id),
    KEY idx_public_chat_notes_session (session_id, created_at),
    CONSTRAINT fk_public_chat_notes_session FOREIGN KEY (session_id) REFERENCES public_chat_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_escalations (
    escalation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    escalated_by VARCHAR(100) NOT NULL,
    escalated_to VARCHAR(100) DEFAULT NULL,
    reason TEXT NOT NULL,
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'high',
    escalation_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolution_deadline DATETIME DEFAULT NULL,
    outcome TEXT DEFAULT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME DEFAULT NULL,
    PRIMARY KEY (escalation_id),
    KEY idx_public_chat_escalations_session (session_id, status),
    CONSTRAINT fk_public_chat_escalations_session FOREIGN KEY (session_id) REFERENCES public_chat_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_tickets (
    ticket_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    ticket_reference VARCHAR(40) NOT NULL,
    subject VARCHAR(220) NOT NULL,
    description TEXT DEFAULT NULL,
    ticket_type VARCHAR(60) NOT NULL DEFAULT 'Follow-up required',
    status ENUM('New','Assigned','In progress','Awaiting public user','Escalated','Resolved','Closed','Reopened') NOT NULL DEFAULT 'New',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    created_by VARCHAR(100) DEFAULT NULL,
    assigned_to VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolution_notes TEXT DEFAULT NULL,
    closed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (ticket_id),
    UNIQUE KEY uniq_public_chat_ticket_reference (ticket_reference),
    KEY idx_public_chat_tickets_session (session_id),
    KEY idx_public_chat_tickets_status (status),
    CONSTRAINT fk_public_chat_tickets_session FOREIGN KEY (session_id) REFERENCES public_chat_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_feedback (
    feedback_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED DEFAULT NULL,
    comments TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (feedback_id),
    UNIQUE KEY uniq_public_chat_feedback_session (session_id),
    CONSTRAINT fk_public_chat_feedback_session FOREIGN KEY (session_id) REFERENCES public_chat_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_canned_responses (
    response_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    inquiry_category VARCHAR(80) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (response_id),
    KEY idx_public_chat_canned_category (inquiry_category, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_settings (
    setting_key VARCHAR(120) NOT NULL,
    setting_value LONGTEXT NOT NULL,
    updated_by VARCHAR(100) DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_audit_logs (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED DEFAULT NULL,
    actor_user_id VARCHAR(100) DEFAULT NULL,
    actor_name VARCHAR(160) DEFAULT NULL,
    actor_role VARCHAR(80) DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    details LONGTEXT DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY idx_public_chat_audit_session (session_id),
    KEY idx_public_chat_audit_action (action),
    KEY idx_public_chat_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS public_chat_rate_limits (
    rate_key VARCHAR(160) NOT NULL,
    action_name VARCHAR(60) NOT NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rate_key, action_name),
    KEY idx_public_chat_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO tb_app_settings (setting_key, setting_value)
VALUES
('public_chat_enabled', '1'),
('public_chat_public_pages_enabled', '1'),
('public_chat_pensioner_portal_enabled', '1'),
('public_chat_attachments_enabled', '0'),
('public_chat_auto_assign_enabled', '0'),
('public_chat_home_enabled', '1'),
('public_chat_about_enabled', '1'),
('public_chat_faq_enabled', '1'),
('public_chat_podcast_enabled', '1'),
('public_chat_feedback_page_enabled', '1'),
('public_chat_terms_enabled', '1'),
('public_chat_max_message_length', '2000'),
('public_chat_poll_interval_ms', '2500'),
('public_chat_welcome_text', 'Welcome to UPS PensionsGo public support. How can we help?'),
('public_chat_consent_text', 'I consent to UPS PensionsGo using these details to respond to this support request.'),
('public_chat_working_hours', '08:00-17:00'),
('public_chat_max_active_chats_per_agent', '5'),
('public_chat_allowed_attachment_types', 'pdf,jpg,jpeg,png,doc,docx'),
('public_chat_max_attachment_size_mb', '5'),
('public_chat_transcript_enabled', '1'),
('public_chat_feedback_enabled', '1'),
('public_chat_rate_limit_start_per_10min', '5'),
('public_chat_rate_limit_messages_per_5min', '20'),
('public_chat_offline_message', 'Public live support is currently unavailable. Please leave a message and the pensions team will follow up.')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
