<?php
require_once __DIR__ . '/../config.php';

function ensureTaskCompletionQueueTable(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS tb_task_completion_queue (
            queue_id INT(11) NOT NULL AUTO_INCREMENT,
            owner_user_id VARCHAR(100) NOT NULL,
            owner_role VARCHAR(60) DEFAULT NULL,
            task_id INT(11) NOT NULL,
            task_type VARCHAR(80) DEFAULT NULL,
            task_title VARCHAR(255) DEFAULT NULL,
            related_reg_no VARCHAR(100) DEFAULT NULL,
            required_assignment_role VARCHAR(60) DEFAULT NULL,
            next_assigned_to VARCHAR(100) DEFAULT NULL,
            next_assigned_role VARCHAR(60) DEFAULT NULL,
            next_priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            action_note TEXT DEFAULT NULL,
            queue_status ENUM('queued','processed','failed','removed') NOT NULL DEFAULT 'queued',
            processed_task_id INT(11) DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (queue_id),
            KEY idx_task_completion_queue_owner_status (owner_user_id, queue_status),
            KEY idx_task_completion_queue_task_owner (task_id, owner_user_id)
        )
    ";
    $conn->query($sql);
    $ensured = true;
}

function getWorkflowTaskById(mysqli $conn, int $taskId): ?array {
    ensureTasksTable($conn);

    $stmt = $conn->prepare("
        SELECT taskId, task_type, related_staff_id, related_reg_no, metadata, assigned_to, assigned_role, created_by, status
        FROM tb_tasks
        WHERE taskId = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $taskId);
    $stmt->execute();
    $result = $stmt->get_result();
    $task = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$task) {
        return null;
    }

    $metadata = [];
    if (!empty($task['metadata'])) {
        $decoded = json_decode((string)$task['metadata'], true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
    $task['_metadata_array'] = $metadata;
    return $task;
}

function getWorkflowTaskRequiredAssignmentRole(string $taskType, array $metadata = []): ?string {
    if ($taskType === 'authorize_writeup') {
        return 'writeup_officer';
    }
    if ($taskType === 'authorize_file_creation') {
        return 'file_creator';
    }
    if ($taskType === 'authorize_data_entry') {
        return 'data_entry';
    }
    if ($taskType === 'review_return') {
        $returnedFrom = $metadata['returned_from'] ?? '';
        if ($returnedFrom === 'writeup') {
            return 'writeup_officer';
        }
        if ($returnedFrom === 'file_creation') {
            return 'file_creator';
        }
        if ($returnedFrom === 'data_entry') {
            return 'data_entry';
        }
    }
    return null;
}

function getWorkflowTaskEffectiveRole(array $task): string {
    $explicitRole = normalizeWorkflowRoleKey((string)($task['assigned_role'] ?? ''));
    if ($explicitRole !== '') {
        return $explicitRole;
    }

    $taskType = trim((string)($task['task_type'] ?? ''));
    $metadata = $task['_metadata_array'] ?? [];
    $requiredRole = getWorkflowTaskRequiredAssignmentRole($taskType, is_array($metadata) ? $metadata : []);
    if ($requiredRole !== null) {
        if ($taskType === 'review_return' || strpos($taskType, 'authorize_') === 0) {
            return 'oc_pen';
        }
        return $requiredRole;
    }

    $roleMap = [
        'writeup' => 'writeup_officer',
        'file_creation' => 'file_creator',
        'data_entry' => 'data_entry',
        'assessment' => 'assessor',
        'audit' => 'auditor',
        'approval' => 'approver'
    ];

    return $roleMap[$taskType] ?? '';
}

function getWorkflowUserRoleById(mysqli $conn, string $userId): ?string {
    if ($userId === '') {
        return null;
    }

    $stmt = $conn->prepare("SELECT userRole FROM tb_users WHERE userId = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row['userRole'] ?? null;
}

function canActorManageWorkflowTask(array $taskData, string $currentUserId, string $currentUserRole): bool {
    $normalizedCurrentUserRole = normalizeWorkflowRoleKey($currentUserRole);
    if ($normalizedCurrentUserRole === 'admin') {
        return true;
    }

    $assignedTo = (string)($taskData['assigned_to'] ?? '');
    if ($assignedTo !== '' && $assignedTo === $currentUserId) {
        return true;
    }

    $assignedRole = (string)($taskData['assigned_role'] ?? '');
    return $assignedTo === '' && $assignedRole !== '' && rolesAreWorkflowEquivalent($assignedRole, $currentUserRole);
}

function createWorkflowFollowUpTask(
    mysqli $conn,
    array $taskData,
    array $baseMetadata,
    string $createdBy,
    string $taskType,
    string $taskTitle,
    string $taskDescription,
    string $priority,
    ?string $assignedTo,
    ?string $assignedRole
): array {
    $parentTaskId = (int)($taskData['taskId'] ?? 0);
    if ($parentTaskId <= 0) {
        return ['success' => false, 'message' => 'Invalid parent task.'];
    }

    $existingStmt = $conn->prepare("
        SELECT taskId
        FROM tb_tasks
        WHERE parent_task_id = ?
        LIMIT 1
    ");
    if ($existingStmt) {
        $existingStmt->bind_param("i", $parentTaskId);
        $existingStmt->execute();
        $existingTask = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if ($existingTask) {
            return ['success' => false, 'message' => 'This task has already been forwarded.'];
        }
    }

    if (!empty($assignedTo) && !empty($taskData['assigned_to']) && $assignedTo === $taskData['assigned_to']) {
        return ['success' => false, 'message' => 'Task cannot be forwarded to the same user.'];
    }

    $metadata = is_array($baseMetadata) ? $baseMetadata : [];
    $metadata['previous_task'] = (string)($taskData['task_type'] ?? '');
    $metadata['previous_task_id'] = $parentTaskId;
    $metadata['forwarded_by'] = $createdBy;

    $newTaskId = createWorkflowTask($conn, [
        'created_by' => $createdBy,
        'assigned_to' => $assignedTo,
        'assigned_role' => $assignedRole,
        'task_type' => $taskType,
        'task_title' => $taskTitle,
        'task_description' => $taskDescription,
        'status' => 'pending',
        'priority' => $priority,
        'related_staff_id' => $taskData['related_staff_id'] ?? null,
        'related_reg_no' => $taskData['related_reg_no'] ?? null,
        'parent_task_id' => $parentTaskId,
        'metadata' => $metadata
    ]);

    if (!$newTaskId) {
        return ['success' => false, 'message' => 'Unable to create follow-up task.'];
    }

    return ['success' => true, 'task_id' => (int)$newTaskId];
}
function completeWorkflowTask(
    mysqli $conn,
    array $taskData,
    string $actorUserId,
    string $actorUserRole,
    string $reason = '',
    string $nextAssignedTo = '',
    string $nextPriority = 'normal'
): array {
    ensureApplicationQueueTable($conn);
    ensureAppnStatusTrackingColumns($conn);
    ensureFileMovementTables($conn);
    ensureStaffDueBaseColumns($conn);
    ensureStaffDueExtendedColumns($conn);
    ensureStaffDueWorkflowColumns($conn);

    $nextPriority = strtolower(trim($nextPriority));
    if (!in_array($nextPriority, ['low', 'normal', 'high', 'urgent'], true)) {
        $nextPriority = 'normal';
    }

    $taskType = (string)($taskData['task_type'] ?? '');
    $metadata = is_array($taskData['_metadata_array'] ?? null) ? $taskData['_metadata_array'] : [];
    $currentTaskStatus = strtolower((string)($taskData['status'] ?? ''));

    if (in_array($currentTaskStatus, ['completed', 'declined', 'cancelled'], true)) {
        return ['success' => false, 'message' => 'This task is already closed.'];
    }

    $requiresAssignment = ['authorize_writeup', 'authorize_file_creation', 'authorize_data_entry', 'review_return'];
    if (in_array($taskType, $requiresAssignment, true) && $nextAssignedTo === '') {
        return ['success' => false, 'message' => 'Select a user to assign before completing this task.'];
    }

    $requiredAssignmentRole = getWorkflowTaskRequiredAssignmentRole($taskType, $metadata);

    if ($requiredAssignmentRole !== null && $nextAssignedTo !== '') {
        $nextRole = getWorkflowUserRoleById($conn, $nextAssignedTo);
        if ($nextRole === null || !rolesAreWorkflowEquivalent($nextRole, $requiredAssignmentRole)) {
            return ['success' => false, 'message' => "Selected assignee must have role {$requiredAssignmentRole}."];
        }
    }

    if ($nextAssignedTo !== '') {
        if ($nextAssignedTo === $actorUserId) {
            return ['success' => false, 'message' => 'Task cannot be forwarded to the same user.'];
        }

        if (!empty($taskData['assigned_to']) && $nextAssignedTo === $taskData['assigned_to']) {
            return ['success' => false, 'message' => 'Task cannot be forwarded to the same user.'];
        }

        $nextRole = getWorkflowUserRoleById($conn, $nextAssignedTo);
        if ($nextRole === 'pensioner') {
            return ['success' => false, 'message' => 'Pensioner accounts cannot be assigned workflow tasks.'];
        }

        if (isset($metadata['assignment_history']) && is_array($metadata['assignment_history']) && in_array($nextAssignedTo, $metadata['assignment_history'], true)) {
            return ['success' => false, 'message' => 'This task has already been assigned to the selected user.'];
        }
    }

    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET status = 'completed', completed_at = NOW(), updated_at = NOW()
        WHERE taskId = ?
    ");
    if ($stmt) {
        $taskId = (int)($taskData['taskId'] ?? 0);
        $stmt->bind_param("i", $taskId);
        $stmt->execute();
        $stmt->close();
    }

    $workflowMap = [
        'authorize_writeup' => ['type' => 'writeup', 'role' => 'writeup_officer', 'title' => 'Write-up'],
        'writeup' => ['type' => 'authorize_file_creation', 'role' => 'oc_pen', 'title' => 'Authorize File Creation'],
        'authorize_file_creation' => ['type' => 'file_creation', 'role' => 'file_creator', 'title' => 'Pension File Creation'],
        'file_creation' => ['type' => 'authorize_data_entry', 'role' => 'oc_pen', 'title' => 'Authorize Data Entry'],
        'authorize_data_entry' => ['type' => 'data_entry', 'role' => 'data_entry', 'title' => 'Data Entry'],
        'data_entry' => ['type' => 'assessment', 'role' => 'assessor', 'title' => 'Assessment'],
        'assessment' => ['type' => 'audit', 'role' => 'auditor', 'title' => 'Audit'],
        'audit' => ['type' => 'approval', 'role' => 'approver', 'title' => 'Approval']
    ];

    $staffId = $taskData['related_staff_id'] ?? null;
    $regNo = $taskData['related_reg_no'] ?? null;
    $followUpTaskId = null;

    if ($taskType === 'review_return') {
        $returnedFrom = $metadata['returned_from'] ?? '';
        $returnMap = [
            'writeup' => ['type' => 'writeup', 'role' => 'writeup_officer', 'title' => 'Write-up'],
            'file_creation' => ['type' => 'file_creation', 'role' => 'file_creator', 'title' => 'Pension File Creation'],
            'data_entry' => ['type' => 'data_entry', 'role' => 'data_entry', 'title' => 'Data Entry']
        ];
        if (isset($returnMap[$returnedFrom])) {
            $next = $returnMap[$returnedFrom];
            $selectedRole = null;
            if ($nextAssignedTo !== '') {
                $selectedRole = normalizeWorkflowRoleKey(getWorkflowUserRoleById($conn, $nextAssignedTo) ?? '');
                if ($selectedRole === '') {
                    $selectedRole = null;
                }
            }
            $followUp = createWorkflowFollowUpTask(
                $conn,
                $taskData,
                ['returned_from' => $returnedFrom],
                $actorUserId,
                $next['type'],
                $next['title'],
                'Task re-assigned after OC/Pen review.',
                $nextPriority,
                $nextAssignedTo !== '' ? $nextAssignedTo : null,
                $nextAssignedTo !== '' ? $selectedRole : $next['role']
            );
            if (empty($followUp['success'])) {
                return ['success' => false, 'message' => $followUp['message'] ?? 'Unable to forward task.'];
            }
            $followUpTaskId = (int)($followUp['task_id'] ?? 0);

            $queueStmt = $conn->prepare("
                UPDATE tb_application_queue
                SET current_stage = ?, status = 'in_progress'
                WHERE staffdue_id = ?
            ");
            if ($queueStmt) {
                $queueStmt->bind_param("si", $next['type'], $staffId);
                $queueStmt->execute();
                $queueStmt->close();
            }
        }
    } elseif (isset($workflowMap[$taskType])) {
        $next = $workflowMap[$taskType];
        $selectedRole = null;
        if ($nextAssignedTo !== '') {
            $selectedRole = normalizeWorkflowRoleKey(getWorkflowUserRoleById($conn, $nextAssignedTo) ?? '');
            if ($selectedRole === '') {
                $selectedRole = null;
            }
        }
        $followUp = createWorkflowFollowUpTask(
            $conn,
            $taskData,
            [],
            $actorUserId,
            $next['type'],
            $next['title'],
            'Workflow task generated automatically.',
            $nextPriority,
            $nextAssignedTo !== '' ? $nextAssignedTo : null,
            $nextAssignedTo !== '' ? $selectedRole : $next['role']
        );
        if (empty($followUp['success'])) {
            return ['success' => false, 'message' => $followUp['message'] ?? 'Unable to forward task.'];
        }
        $followUpTaskId = (int)($followUp['task_id'] ?? 0);

        $queueStmt = $conn->prepare("
            UPDATE tb_application_queue
            SET current_stage = ?, status = 'in_progress'
            WHERE staffdue_id = ?
        ");
        if ($queueStmt) {
            $queueStmt->bind_param("si", $next['type'], $staffId);
            $queueStmt->execute();
            $queueStmt->close();
        }
    } else {
        if ($taskType === 'approval') {
            $queueStmt = $conn->prepare("
                UPDATE tb_application_queue
                SET current_stage = 'completed', status = 'completed'
                WHERE staffdue_id = ?
            ");
            if ($queueStmt) {
                $queueStmt->bind_param("i", $staffId);
                $queueStmt->execute();
                $queueStmt->close();
            }

            if ($regNo) {
                $comment = $reason !== '' ? $reason : null;
                updateAppnStatusStep($conn, $regNo, 'approval', 'Approved', $comment, $actorUserId);
            }

            if (!$staffId && $regNo) {
                $staffLookup = $conn->prepare("SELECT id FROM tb_staffdue WHERE regNo = ? LIMIT 1");
                if ($staffLookup) {
                    $staffLookup->bind_param("s", $regNo);
                    $staffLookup->execute();
                    $staffRow = $staffLookup->get_result()->fetch_assoc();
                    if ($staffRow) {
                        $staffId = (int)$staffRow['id'];
                    }
                    $staffLookup->close();
                }
            }

            if ($staffId && !$regNo) {
                $regLookup = $conn->prepare("SELECT regNo FROM tb_staffdue WHERE id = ? LIMIT 1");
                if ($regLookup) {
                    $regLookup->bind_param("i", $staffId);
                    $regLookup->execute();
                    $regRow = $regLookup->get_result()->fetch_assoc();
                    if ($regRow) {
                        $regNo = $regRow['regNo'] ?? $regNo;
                    }
                    $regLookup->close();
                }
            }

            $completionReason = $reason !== '' ? $reason : 'Application approved and completed';
            if ($staffId) {
                $staffCompleteStmt = $conn->prepare("
                    UPDATE tb_staffdue
                    SET appnStatus = 'completed',
                        appn_status_at = NOW(),
                        appn_status_by = ?,
                        appn_status_reason = ?
                    WHERE id = ?
                ");
                if ($staffCompleteStmt) {
                    $staffCompleteStmt->bind_param("ssi", $actorUserId, $completionReason, $staffId);
                    $staffCompleteStmt->execute();
                    $staffCompleteStmt->close();
                }
            } elseif ($regNo) {
                $staffCompleteByRegStmt = $conn->prepare("
                    UPDATE tb_staffdue
                    SET appnStatus = 'completed',
                        appn_status_at = NOW(),
                        appn_status_by = ?,
                        appn_status_reason = ?
                    WHERE regNo = ?
                ");
                if ($staffCompleteByRegStmt) {
                    $staffCompleteByRegStmt->bind_param("sss", $actorUserId, $completionReason, $regNo);
                    $staffCompleteByRegStmt->execute();
                    $staffCompleteByRegStmt->close();
                }
            }
            if ($staffId) {
                $staffStmt = $conn->prepare("SELECT * FROM tb_staffdue WHERE id = ? LIMIT 1");
                if ($staffStmt) {
                    $staffStmt->bind_param("i", $staffId);
                    $staffStmt->execute();
                    $staffResult = $staffStmt->get_result();
                    if ($staffRow = $staffResult->fetch_assoc()) {
                        if (!$regNo && isset($staffRow['regNo'])) {
                            $regNo = $staffRow['regNo'];
                        }

                        $currentSubmission = strtolower(trim((string)($staffRow['submissionStatus'] ?? '')));
                        if ($currentSubmission !== 'submitted') {
                            $submitStmt = $conn->prepare("
                                UPDATE tb_staffdue
                                SET submissionStatus = 'submitted',
                                    submission_at = COALESCE(submission_at, NOW()),
                                    submission_by = COALESCE(submission_by, ?)
                                WHERE id = ?
                            ");
                            if ($submitStmt) {
                                $submitStmt->bind_param("si", $actorUserId, $staffId);
                                $submitStmt->execute();
                                $submitStmt->close();
                            }
                            if ($regNo) {
                                updateAppnStatusStep($conn, $regNo, 'verification', 'Pending', 'Submitted for verification', $actorUserId);
                            }
                        }

                        $accountResult = upsertPensionerUserFromStaffDue($conn, (int)$staffId, 'Pensioner123', $actorUserId);
                        if (empty($accountResult['success']) && function_exists('logAuditEvent')) {
                            logAuditEvent($conn, [
                                'actor_id' => $actorUserId,
                                'actor_name' => $_SESSION['userName'] ?? 'System',
                                'actor_role' => $actorUserRole,
                                'action' => 'pensioner_account_sync_failed',
                                'entity_type' => 'user',
                                'entity_id' => $regNo ?? '',
                                'details' => [
                                    'message' => $accountResult['message'] ?? 'Pensioner account sync failed after approval.'
                                ]
                            ]);
                        }

                        $existsStmt = $conn->prepare("SELECT id, boxNo, payType, dateOfDeath FROM tb_fileregistry WHERE regNo = ? LIMIT 1");
                        $existsId = null;
                        $existingBoxNo = '';
                        $existingPayType = '';
                        $existingDateOfDeath = '';
                        if ($existsStmt) {
                            $existsStmt->bind_param("s", $regNo);
                            $existsStmt->execute();
                            $existsRow = $existsStmt->get_result()->fetch_assoc();
                            $existsId = $existsRow['id'] ?? null;
                            $existingBoxNo = trim((string)($existsRow['boxNo'] ?? ''));
                            $existingPayType = trim((string)($existsRow['payType'] ?? ''));
                            $existingDateOfDeath = trim((string)($existsRow['dateOfDeath'] ?? ''));
                            $existsStmt->close();
                        }

                        $livingStatusVal = deriveStaffLivingStatus($staffRow);
                        $computerNo = trim((string)($staffRow['computerNo'] ?? '')) ?: null;
                        $titleVal = trim((string)($staffRow['title'] ?? '')) ?: null;
                        $sNameVal = trim((string)($staffRow['sName'] ?? '')) ?: null;
                        $fNameVal = trim((string)($staffRow['fName'] ?? '')) ?: null;
                        $genderVal = $staffRow['gender'] ?? null;
                        $birthDateVal = $staffRow['birthDate'] ?? null;
                        $enlistmentVal = $staffRow['enlistmentDate'] ?? null;
                        $retireVal = $staffRow['retirementDate'] ?? null;
                        $retireTypeVal = normalizeBenefitsRetirementTypeKey((string)($staffRow['retirementType'] ?? ''));
                        if ($retireTypeVal === '') {
                            $retireTypeVal = null;
                        }
                        $payTypeVal = deriveRegistryPayTypeFromProfile(
                            $retireTypeVal,
                            $enlistmentVal,
                            $retireVal,
                            $staffRow['payType'] ?? $existingPayType ?? null
                        );
                        $lifeCertificateVal = isLifeCertificateExemptRecord($livingStatusVal, $payTypeVal) ? 'Exempt' : 'Not Submitted';
                        $allocatedBoxNo = $existingBoxNo !== ''
                            ? $existingBoxNo
                            : allocateRegistryBoxNumber($conn, $livingStatusVal, $payTypeVal);
                        $dateOn15yrsVal = computeDateOn15Years($staffRow['retirementDate'] ?? null);
                        $estateLifecycleVal = evaluatePensionEstateLifecycle(
                            $retireVal,
                            $payTypeVal,
                            $livingStatusVal,
                            $existingDateOfDeath !== '' ? $existingDateOfDeath : null
                        );
                        $estateExpiryDateVal = $estateLifecycleVal['estateExpiryDate'] ?? null;
                        $estateStatusVal = $estateLifecycleVal['label'] ?? null;
                        $tinVal = trim((string)($staffRow['TIN'] ?? '')) ?: null;
                        $ninVal = trim((string)($staffRow['NIN'] ?? '')) ?: null;
                        $addressVal = trim((string)($staffRow['address'] ?? '')) ?: null;
                        $telNoVal = trim((string)($staffRow['telNo'] ?? '')) ?: null;
                        $emailVal = trim((string)($staffRow['applicant_email'] ?? '')) ?: null;
                        $nextOfKinVal = trim((string)($staffRow['next_of_kin'] ?? '')) ?: null;
                        $nextOfKinContactVal = trim((string)($staffRow['next_of_kin_contact'] ?? '')) ?: null;
                        $bankNameVal = trim((string)($staffRow['bank_name'] ?? '')) ?: null;
                        $bankAccountVal = trim((string)($staffRow['bank_account'] ?? '')) ?: null;
                        $bankBranchVal = trim((string)($staffRow['bank_branch'] ?? '')) ?: null;
                        $monthlySalaryVal = $staffRow['monthlySalary'] ?? null;
                        $lengthOfServiceVal = $staffRow['lengthOfService'] ?? null;
                        $annualSalaryVal = $staffRow['annualSalary'] ?? null;
                        $reducedPensionVal = $staffRow['reducedPension'] ?? null;
                        $fullPensionVal = $staffRow['fullPension'] ?? null;
                        $gratuityVal = $staffRow['gratuity'] ?? null;
                        $benefitSnapshot = calculateBenefitSnapshotFromInputs(
                            $retireTypeVal,
                            $enlistmentVal,
                            $retireVal,
                            $monthlySalaryVal,
                            $birthDateVal
                        );
                        if (($benefitSnapshot['lengthOfService'] ?? null) !== null) {
                            $lengthOfServiceVal = $benefitSnapshot['lengthOfService'];
                        }
                        if (($benefitSnapshot['annualSalary'] ?? null) !== null) {
                            $annualSalaryVal = $benefitSnapshot['annualSalary'];
                        }
                        if (($benefitSnapshot['reducedPension'] ?? null) !== null) {
                            $reducedPensionVal = $benefitSnapshot['reducedPension'];
                        }
                        if (($benefitSnapshot['fullPension'] ?? null) !== null) {
                            $fullPensionVal = $benefitSnapshot['fullPension'];
                        }
                        if (($benefitSnapshot['gratuity'] ?? null) !== null) {
                            $gratuityVal = $benefitSnapshot['gratuity'];
                        }
                        $otherVal = json_encode([
                            'next_of_kin' => $nextOfKinVal,
                            'next_of_kin_contact' => $nextOfKinContactVal,
                            'bank_name' => $bankNameVal,
                            'bank_account' => $bankAccountVal,
                            'bank_branch' => $bankBranchVal
                        ], JSON_UNESCAPED_SLASHES);

                        if ($existsId) {
                            $updateStmt = $conn->prepare("
                                UPDATE tb_fileregistry
                                SET availability_status = 'out_of_shelf',
                                    availability_reason = 'Still with Approver',
                                    livingStatus = ?,
                                    boxNo = ?,
                                    computerNo = ?,
                                    title = ?,
                                    sName = ?,
                                    fName = ?,
                                    gender = ?,
                                    birthDate = ?,
                                    enlistmentDate = ?,
                                    retirementDate = ?,
                                    retirementType = ?,
                                    TIN = ?,
                                    NIN = ?,
                                    address = ?,
                                    telNo = ?,
                                    applicant_email = ?,
                                    next_of_kin = ?,
                                    next_of_kin_contact = ?,
                                    bank_name = ?,
                                    bank_account = ?,
                                    bank_branch = ?,
                                    monthlySalary = ?,
                                    lengthOfService = ?,
                                    annualSalary = ?,
                                    reducedPension = ?,
                                    fullPension = ?,
                                    gratuity = ?,
                                    lifeCertificate = ?,
                                    payrollStatus = 'Not on Payroll',
                                    payType = ?,
                                    dateOn15yrs = ?,
                                    estateExpiryDate = ?,
                                    estateStatus = ?,
                                    other = ?
                                WHERE regNo = ?
                            ");
                            if ($updateStmt) {
                                $updateTypes = str_repeat('s', 34);
                                $updateStmt->bind_param(
                                    $updateTypes,
                                    $livingStatusVal,
                                    $allocatedBoxNo,
                                    $computerNo,
                                    $titleVal,
                                    $sNameVal,
                                    $fNameVal,
                                    $genderVal,
                                    $birthDateVal,
                                    $enlistmentVal,
                                    $retireVal,
                                    $retireTypeVal,
                                    $tinVal,
                                    $ninVal,
                                    $addressVal,
                                    $telNoVal,
                                    $emailVal,
                                    $nextOfKinVal,
                                    $nextOfKinContactVal,
                                    $bankNameVal,
                                    $bankAccountVal,
                                    $bankBranchVal,
                                    $monthlySalaryVal,
                                    $lengthOfServiceVal,
                                    $annualSalaryVal,
                                    $reducedPensionVal,
                                    $fullPensionVal,
                                    $gratuityVal,
                                    $lifeCertificateVal,
                                    $payTypeVal,
                                    $dateOn15yrsVal,
                                    $estateExpiryDateVal,
                                    $estateStatusVal,
                                    $otherVal,
                                    $regNo
                                );
                                $updateStmt->execute();
                                $updateStmt->close();
                            }
                        } else {
                            $insertStmt = $conn->prepare("
                                INSERT INTO tb_fileregistry
                                (computerNo, regNo, title, sName, fName, gender, livingStatus, lifeCertificate,
                                 boxNo, birthDate, enlistmentDate, retirementDate, retirementType, TIN, NIN, address,
                                 telNo, applicant_email, next_of_kin, next_of_kin_contact, bank_name, bank_account, bank_branch,
                                 monthlySalary, lengthOfService, annualSalary, reducedPension, fullPension, gratuity,
                                 payrollStatus, payType, dateOn15yrs, estateExpiryDate, estateStatus, periodTo15yrs, periodFrom15yrs, other, availability_status, availability_reason)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Not on Payroll', ?, ?, ?, ?, NULL, NULL, ?, 'out_of_shelf', 'Still with Approver')
                            ");
                            if ($insertStmt) {
                                $insertTypes = str_repeat('s', 34);
                                $insertStmt->bind_param(
                                    $insertTypes,
                                    $computerNo,
                                    $regNo,
                                    $titleVal,
                                    $sNameVal,
                                    $fNameVal,
                                    $genderVal,
                                    $livingStatusVal,
                                    $lifeCertificateVal,
                                    $allocatedBoxNo,
                                    $birthDateVal,
                                    $enlistmentVal,
                                    $retireVal,
                                    $retireTypeVal,
                                    $tinVal,
                                    $ninVal,
                                    $addressVal,
                                    $telNoVal,
                                    $emailVal,
                                    $nextOfKinVal,
                                    $nextOfKinContactVal,
                                    $bankNameVal,
                                    $bankAccountVal,
                                    $bankBranchVal,
                                    $monthlySalaryVal,
                                    $lengthOfServiceVal,
                                    $annualSalaryVal,
                                    $reducedPensionVal,
                                    $fullPensionVal,
                                    $gratuityVal,
                                    $payTypeVal,
                                    $dateOn15yrsVal,
                                    $estateExpiryDateVal,
                                    $estateStatusVal,
                                    $otherVal
                                );
                                $insertStmt->execute();
                                $insertStmt->close();
                            }
                        }

                        $movementStmt = $conn->prepare("
                            INSERT INTO tb_file_movements
                            (regNo, file_id, from_office, to_office, reason, delivered_by, received_by, moved_at)
                            VALUES (?, NULL, ?, ?, ?, ?, ?, NOW())
                        ");
                        if ($movementStmt) {
                            $fromOffice = 'Auditor';
                            $toOffice = 'Approver';
                            $reasonVal = 'Still with Approver';
                            $deliveredByVal = 'Auditor';
                            $receivedByVal = 'Approver';
                            $movementStmt->bind_param(
                                "ssssss",
                                $regNo,
                                $fromOffice,
                                $toOffice,
                                $reasonVal,
                                $deliveredByVal,
                                $receivedByVal
                            );
                            $movementStmt->execute();
                            $movementStmt->close();
                        }

                        if (function_exists('maybeReconcileAllActivePayrollCycles')) {
                            try {
                                maybeReconcileAllActivePayrollCycles($conn);
                            } catch (Throwable $syncError) {
                                error_log('completeWorkflowTask payroll reconciliation failed: ' . $syncError->getMessage());
                            }
                        }

                        if ($regNo && function_exists('markRegistryRecordWorkflowAutoArrearsEligible')) {
                            try {
                                markRegistryRecordWorkflowAutoArrearsEligible($conn, $regNo, 'workflow_approval');
                            } catch (Throwable $arrearsFlagError) {
                                error_log('completeWorkflowTask arrears eligibility flag failed: ' . $arrearsFlagError->getMessage());
                            }
                        }

                        if ($regNo && function_exists('runAutomaticArrearsReconciliation')) {
                            try {
                                runAutomaticArrearsReconciliation($conn, $regNo);
                            } catch (Throwable $arrearsError) {
                                error_log('completeWorkflowTask arrears reconciliation failed: ' . $arrearsError->getMessage());
                            }
                        }
                    }
                    $staffStmt->close();
                }
            }
        }
    }
    if ($regNo) {
        $comment = $reason !== '' ? $reason : null;
        if ($taskType === 'writeup') {
            updateAppnStatusStep($conn, $regNo, 'writeUp', 'Completed', $comment, $actorUserId);
        }
        if ($taskType === 'file_creation') {
            updateAppnStatusStep($conn, $regNo, 'fileCreation', 'Completed', $comment, $actorUserId);
        }
        if ($taskType === 'authorize_data_entry') {
            updateAppnStatusStep($conn, $regNo, 'entrantAllocation', 'Authorized', $comment, $actorUserId);
        }
        if ($taskType === 'data_entry') {
            updateAppnStatusStep($conn, $regNo, 'dataCapture', 'Completed', $comment, $actorUserId);
        }
        if ($taskType === 'assessment') {
            updateAppnStatusStep($conn, $regNo, 'assessment', 'Completed', $comment, $actorUserId);
        }
        if ($taskType === 'audit') {
            updateAppnStatusStep($conn, $regNo, 'audit', 'Completed', $comment, $actorUserId);
        }
    }

    if (function_exists('recordWorkflowLog')) {
        recordWorkflowLog($conn, [
            'task_id' => (int)($taskData['taskId'] ?? 0),
            'staffdue_id' => (int)($taskData['related_staff_id'] ?? 0),
            'regNo' => (string)($taskData['related_reg_no'] ?? ''),
            'action' => 'task_completed',
            'from_status' => $currentTaskStatus,
            'to_status' => 'completed',
            'actor_id' => $actorUserId,
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $actorUserRole,
            'note' => $reason,
            'metadata' => [
                'follow_up_task_id' => $followUpTaskId,
                'task_type' => $taskType
            ]
        ]);
    }

    return [
        'success' => true,
        'message' => 'Task completed.',
        'follow_up_task_id' => $followUpTaskId
    ];
}
