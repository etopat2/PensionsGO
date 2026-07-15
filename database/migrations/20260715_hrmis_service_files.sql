-- HRMIS identity alignment and service-file lifecycle (idempotent on MariaDB 10.3+).
ALTER TABLE tb_staffdue
  ADD COLUMN IF NOT EXISTS employeeNo VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS ippsNo VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rankPosition VARCHAR(160) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS rankName VARCHAR(160) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS positionName VARCHAR(160) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS firstName VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS middleName VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS lastName VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS next_of_kin_nin VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS salaryScale VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS employmentStatus VARCHAR(80) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS tribe VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS homeDistrict VARCHAR(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS homeRegion VARCHAR(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS religion VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS country VARCHAR(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS subCounty VARCHAR(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS parish VARCHAR(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS village VARCHAR(120) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS alternateTelNo VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS maritalStatus VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS service_file_status VARCHAR(40) NOT NULL DEFAULT 'pending_processing',
  ADD COLUMN IF NOT EXISTS service_file_location VARCHAR(160) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS tb_staff_verification_documents (
  verification_document_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  staffdue_id INT NOT NULL, document_code VARCHAR(80) NOT NULL,
  document_label VARCHAR(180) NOT NULL, is_present TINYINT(1) NOT NULL DEFAULT 0,
  verified_by VARCHAR(100) DEFAULT NULL, verified_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_staff_verification_document (staffdue_id, document_code),
  KEY idx_staff_verification_staff (staffdue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_pension_beneficiaries (
  beneficiary_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, deceased_staffdue_id INT DEFAULT NULL,
  deceased_registry_id INT DEFAULT NULL, deceased_ipps_no VARCHAR(80) NOT NULL,
  beneficiary_type VARCHAR(50) NOT NULL DEFAULT 'administrator', first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) NOT NULL, beneficiary_nin VARCHAR(50) NOT NULL,
  beneficiary_ipps_no VARCHAR(80) DEFAULT NULL, beneficiary_supplier_no VARCHAR(80) DEFAULT NULL,
  telephone VARCHAR(50) DEFAULT NULL, email VARCHAR(120) DEFAULT NULL, relationship_to_deceased VARCHAR(80) DEFAULT NULL,
  administration_reference VARCHAR(120) DEFAULT NULL, earning_start_date DATE DEFAULT NULL, earning_end_date DATE DEFAULT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, notes TEXT DEFAULT NULL,
  created_by VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT NULL,
  KEY idx_beneficiary_deceased_ipps (deceased_ipps_no), KEY idx_beneficiary_nin (beneficiary_nin),
  KEY idx_beneficiary_supplier (beneficiary_supplier_no), KEY idx_beneficiary_staff (deceased_staffdue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_service_files (
  service_file_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  staffdue_id INT NOT NULL, employeeNo VARCHAR(80) NOT NULL, pensionNo VARCHAR(80) DEFAULT NULL,
  registry_stage VARCHAR(40) NOT NULL DEFAULT 'pending_processing', shelf_reference VARCHAR(120) DEFAULT NULL,
  bunch_reference VARCHAR(120) DEFAULT NULL, availability_status VARCHAR(40) NOT NULL DEFAULT 'not_availed',
  current_holder VARCHAR(160) DEFAULT NULL, availed_at DATETIME DEFAULT NULL,
  pension_file_created_at DATETIME DEFAULT NULL, gratuity_paid_at DATETIME DEFAULT NULL,
  archived_at DATETIME DEFAULT NULL, notes TEXT DEFAULT NULL, updated_by VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_service_file_staff (staffdue_id), UNIQUE KEY uq_service_file_employee (employeeNo),
  KEY idx_service_registry_stage (registry_stage), KEY idx_service_availability (availability_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tb_file_movements
  ADD COLUMN IF NOT EXISTS file_type VARCHAR(30) NOT NULL DEFAULT 'pension',
  ADD COLUMN IF NOT EXISTS source_registry VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS destination_registry VARCHAR(50) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS movement_direction VARCHAR(20) NOT NULL DEFAULT 'out',
  ADD COLUMN IF NOT EXISTS parent_movement_id INT DEFAULT NULL;
