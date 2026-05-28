<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
        throw new RuntimeException('Authentication required');
    }

    ensureFeedbackWorkflowTables($conn);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $submissionId = (int)($input['submission_id'] ?? 0);
    if ($submissionId <= 0) {
        throw new RuntimeException('Invalid feedback submission.');
    }

    $stmt = $conn->prepare("SELECT * FROM tb_feedback_submissions WHERE submission_id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to load feedback submission.');
    }
    $stmt->bind_param("i", $submissionId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$current) {
        throw new RuntimeException('Feedback submission was not found.');
    }

    $actorId = (string)($_SESSION['userId'] ?? '');
    $actorName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'System User');
    $actorRole = normalizeRoleKey((string)($_SESSION['userRole'] ?? ''));

    $canManage = currentUserHasPermission($conn, 'feedback.manage');
    $isAssignedActor = $actorId !== '' && trim((string)($current['assigned_to_user_id'] ?? '')) === $actorId;
    if (!$canManage && !$isAssignedActor) {
        throw new RuntimeException('Access denied');
    }

    $allowedStatuses = ['new', 'reviewed', 'resolved', 'closed'];
    $allowedPriorities = ['low', 'normal', 'high', 'critical'];

    $targetStatus = strtolower(trim((string)($input['status'] ?? ($current['status'] ?? 'new'))));
    if (!in_array($targetStatus, $allowedStatuses, true)) {
        $targetStatus = strtolower(trim((string)($current['status'] ?? 'new')));
    }

    $targetPriority = strtolower(trim((string)($input['priority'] ?? ($current['priority'] ?? 'normal'))));
    if (!in_array($targetPriority, $allowedPriorities, true)) {
        $targetPriority = strtolower(trim((string)($current['priority'] ?? 'normal')));
        if (!in_array($targetPriority, $allowedPriorities, true)) {
            $targetPriority = 'normal';
        }
    }

    $allowAssignment = $canManage && getAppSettingBool($conn, 'feedback_allow_assignment', true);
    $assignedUserIdInput = trim((string)($input['assigned_to_user_id'] ?? ($current['assigned_to_user_id'] ?? '')));
    $note = trim((string)($input['internal_note'] ?? ''));
    $resolutionSummary = trim((string)($input['resolution_summary'] ?? ($current['resolution_summary'] ?? '')));
    if (!$canManage) {
        $assignedUserIdInput = trim((string)($current['assigned_to_user_id'] ?? ''));
        $targetPriority = strtolower(trim((string)($current['priority'] ?? 'normal')));
    }

    $changes = [];
    $updates = [];
    $updateTypes = '';
    $updateValues = [];
    $assignmentNotification = null;
    $assignmentTaskPayload = null;
    $assigneeSummary = null;

    $hasUserActiveColumn = false;
    if ($columnResult = $conn->query("SHOW COLUMNS FROM tb_users LIKE 'is_active'")) {
        $hasUserActiveColumn = $columnResult->num_rows > 0;
        $columnResult->close();
    }

    if ($targetPriority !== strtolower(trim((string)($current['priority'] ?? 'normal')))) {
        $updates[] = 'priority = ?';
        $updateTypes .= 's';
        $updateValues[] = $targetPriority;
        $changes['priority'] = ['from' => strtolower(trim((string)($current['priority'] ?? 'normal'))), 'to' => $targetPriority];
    }

    if ($targetStatus !== strtolower(trim((string)($current['status'] ?? 'new')))) {
        $updates[] = 'status = ?';
        $updateTypes .= 's';
        $updateValues[] = $targetStatus;
        $changes['status'] = ['from' => strtolower(trim((string)($current['status'] ?? 'new'))), 'to' => $targetStatus];

        if ($targetStatus === 'reviewed') {
            $updates[] = 'reviewed_at = NOW()';
            $updates[] = 'reviewed_by_user_id = ?';
            $updates[] = 'reviewed_by_name = ?';
            $updates[] = 'reviewed_by_role = ?';
            $updateTypes .= 'sss';
            array_push($updateValues, $actorId, $actorName, $actorRole);
            $updates[] = 'closed_at = NULL';
            $updates[] = 'closed_by_user_id = NULL';
            $updates[] = 'closed_by_name = NULL';
            $updates[] = 'closed_by_role = NULL';
        } elseif ($targetStatus === 'resolved') {
            $updates[] = 'reviewed_at = COALESCE(reviewed_at, NOW())';
            $updates[] = 'reviewed_by_user_id = COALESCE(reviewed_by_user_id, ?)';
            $updates[] = 'reviewed_by_name = COALESCE(reviewed_by_name, ?)';
            $updates[] = 'reviewed_by_role = COALESCE(reviewed_by_role, ?)';
            $updateTypes .= 'sss';
            array_push($updateValues, $actorId, $actorName, $actorRole);
            $updates[] = 'resolved_at = NOW()';
            $updates[] = 'resolved_by_user_id = ?';
            $updates[] = 'resolved_by_name = ?';
            $updates[] = 'resolved_by_role = ?';
            $updateTypes .= 'sss';
            array_push($updateValues, $actorId, $actorName, $actorRole);
            $updates[] = 'closed_at = NULL';
            $updates[] = 'closed_by_user_id = NULL';
            $updates[] = 'closed_by_name = NULL';
            $updates[] = 'closed_by_role = NULL';
        } elseif ($targetStatus === 'closed') {
            $updates[] = 'reviewed_at = COALESCE(reviewed_at, NOW())';
            $updates[] = 'reviewed_by_user_id = COALESCE(reviewed_by_user_id, ?)';
            $updates[] = 'reviewed_by_name = COALESCE(reviewed_by_name, ?)';
            $updates[] = 'reviewed_by_role = COALESCE(reviewed_by_role, ?)';
            $updateTypes .= 'sss';
            array_push($updateValues, $actorId, $actorName, $actorRole);
            $updates[] = 'resolved_at = COALESCE(resolved_at, NOW())';
            $updates[] = 'resolved_by_user_id = COALESCE(resolved_by_user_id, ?)';
            $updates[] = 'resolved_by_name = COALESCE(resolved_by_name, ?)';
            $updates[] = 'resolved_by_role = COALESCE(resolved_by_role, ?)';
            $updateTypes .= 'sss';
            array_push($updateValues, $actorId, $actorName, $actorRole);
            $updates[] = 'closed_at = NOW()';
            $updates[] = 'closed_by_user_id = ?';
            $updates[] = 'closed_by_name = ?';
            $updates[] = 'closed_by_role = ?';
            $updateTypes .= 'sss';
            array_push($updateValues, $actorId, $actorName, $actorRole);
        } else {
            $updates[] = 'resolved_at = NULL';
            $updates[] = 'resolved_by_user_id = NULL';
            $updates[] = 'resolved_by_name = NULL';
            $updates[] = 'resolved_by_role = NULL';
            $updates[] = 'closed_at = NULL';
            $updates[] = 'closed_by_user_id = NULL';
            $updates[] = 'closed_by_name = NULL';
            $updates[] = 'closed_by_role = NULL';
        }
    }

    if ($allowAssignment) {
        $currentAssigned = trim((string)($current['assigned_to_user_id'] ?? ''));
        if ($assignedUserIdInput !== $currentAssigned) {
            if ($assignedUserIdInput === '') {
                $updates[] = 'assigned_to_user_id = NULL';
                $updates[] = 'assigned_to_name = NULL';
                $updates[] = 'assigned_to_role = NULL';
                $updates[] = 'assigned_at = NULL';
                $changes['assignment'] = [
                    'from' => $currentAssigned,
                    'to' => ''
                ];
            } else {
                $userSql = "SELECT userId, userName, userEmail, COALESCE(userRole, 'user') AS userRole FROM tb_users WHERE userId = ?";
                if ($hasUserActiveColumn) {
                    $userSql .= " AND COALESCE(is_active, 1) = 1";
                }
                $userSql .= " LIMIT 1";
                $userStmt = $conn->prepare($userSql);
                if (!$userStmt) {
                    throw new RuntimeException('Unable to validate assignee.');
                }
                $userStmt->bind_param("s", $assignedUserIdInput);
                $userStmt->execute();
                $assignee = $userStmt->get_result()->fetch_assoc() ?: null;
                $userStmt->close();

                if (!$assignee) {
                    throw new RuntimeException('The selected assignee was not found.');
                }

                $assignedRole = normalizeRoleKey((string)($assignee['userRole'] ?? ''));
                if ($assignedRole === 'pensioner') {
                    throw new RuntimeException('Pensioners cannot be assigned feedback workflow items.');
                }

                $updates[] = 'assigned_to_user_id = ?';
                $updates[] = 'assigned_to_name = ?';
                $updates[] = 'assigned_to_role = ?';
                $updates[] = 'assigned_at = NOW()';
                $updateTypes .= 'sss';
                array_push($updateValues, (string)$assignee['userId'], (string)$assignee['userName'], $assignedRole);
                $changes['assignment'] = [
                    'from' => $currentAssigned,
                    'to' => (string)$assignee['userId'],
                    'to_name' => (string)$assignee['userName']
                ];
                $assigneeSummary = [
                    'user_id' => (string)$assignee['userId'],
                    'user_name' => (string)$assignee['userName'],
                    'user_role' => $assignedRole
                ];

                $slaDays = max(1, getAppSettingInt($conn, 'feedback_response_sla_days', 5));
                $submittedAtTs = strtotime((string)($current['submitted_at'] ?? '')) ?: time();
                $feedbackDueAt = date('Y-m-d H:i:s', strtotime('+' . $slaDays . ' days', $submittedAtTs));

                $assignmentTaskPayload = [
                    'created_by' => $actorId,
                    'assigned_to' => (string)$assignee['userId'],
                    'assigned_role' => $assignedRole,
                    'task_type' => 'feedback_followup',
                    'task_title' => 'Feedback assignment: ' . (string)($current['subject'] ?? 'Feedback submission'),
                    'task_description' => implode("\n", [
                        'A feedback submission has been assigned to you.',
                        'Reference: ' . (string)($current['reference_no'] ?? ''),
                        'Type: ' . ucwords(str_replace('_', ' ', (string)($current['feedback_type'] ?? ''))),
                        'Audience: ' . getFeedbackAudienceLabel((string)($current['audience'] ?? 'public')),
                        'Priority: ' . ucfirst($targetPriority),
                        'Sender: ' . (string)($current['full_name'] ?? 'Unknown'),
                        'Subject: ' . (string)($current['subject'] ?? 'Feedback submission')
                    ]),
                    'status' => 'pending',
                    'priority' => $targetPriority,
                    'related_reg_no' => (string)($current['reference_no'] ?? ''),
                    'due_at' => $feedbackDueAt,
                    'metadata' => [
                        'submission_id' => $submissionId,
                        'reference_no' => (string)($current['reference_no'] ?? ''),
                        'source' => 'feedback_assignment',
                        'full_name' => (string)($current['full_name'] ?? ''),
                        'email_address' => (string)($current['email_address'] ?? ''),
                        'phone_number' => (string)($current['phone_number'] ?? ''),
                        'subject' => (string)($current['subject'] ?? ''),
                        'message' => (string)($current['message'] ?? ''),
                        'feedback_type' => (string)($current['feedback_type'] ?? ''),
                        'feedback_type_label' => ucwords(str_replace('_', ' ', (string)($current['feedback_type'] ?? ''))),
                        'audience' => (string)($current['audience'] ?? ''),
                        'audience_label' => getFeedbackAudienceLabel((string)($current['audience'] ?? 'public')),
                        'submitted_at' => (string)($current['submitted_at'] ?? ''),
                        'page_context' => (string)($current['page_context'] ?? ''),
                        'priority' => $targetPriority
                    ]
                ];

                if (getAppSettingBool($conn, 'feedback_email_notifications_enabled', true)) {
                    $assigneeEmail = trim((string)($assignee['userEmail'] ?? ''));
                    if ($assigneeEmail !== '' && filter_var($assigneeEmail, FILTER_VALIDATE_EMAIL)) {
                        $assignmentNotification = [
                            'recipient' => $assigneeEmail,
                            'subject' => 'Feedback assigned: ' . (string)($current['subject'] ?? 'Feedback submission'),
                            'message' => implode("\n", [
                                'A feedback item has been assigned to you.',
                                'Reference: ' . (string)($current['reference_no'] ?? ''),
                                'Subject: ' . (string)($current['subject'] ?? ''),
                                'Priority: ' . ucfirst($targetPriority),
                                'Audience: ' . getFeedbackAudienceLabel((string)($current['audience'] ?? 'public')),
                                'Submitted by: ' . (string)($current['full_name'] ?? 'Unknown submitter'),
                                '',
                                'Open Feedback Management in the dashboard to review and respond.'
                            ]),
                            'html_body' => '<p>A feedback item has been assigned to you.</p>'
                                . '<p><strong>Reference:</strong> ' . htmlspecialchars((string)($current['reference_no'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
                                . '<strong>Subject:</strong> ' . htmlspecialchars((string)($current['subject'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
                                . '<strong>Priority:</strong> ' . htmlspecialchars(ucfirst($targetPriority), ENT_QUOTES, 'UTF-8') . '<br>'
                                . '<strong>Audience:</strong> ' . htmlspecialchars(getFeedbackAudienceLabel((string)($current['audience'] ?? 'public')), ENT_QUOTES, 'UTF-8') . '<br>'
                                . '<strong>Submitted by:</strong> ' . htmlspecialchars((string)($current['full_name'] ?? 'Unknown submitter'), ENT_QUOTES, 'UTF-8') . '</p>'
                                . '<p>Open Feedback Management in the dashboard to review and respond.</p>'
                        ];
                    }
                }
            }
        }
    }

    if ($resolutionSummary !== trim((string)($current['resolution_summary'] ?? ''))) {
        $updates[] = 'resolution_summary = ?';
        $updateTypes .= 's';
        $updateValues[] = $resolutionSummary;
        $changes['resolution_summary'] = [
            'from' => trim((string)($current['resolution_summary'] ?? '')),
            'to' => $resolutionSummary
        ];
    }

    if ($note !== '') {
        $updates[] = 'updated_at = NOW()';
    }

    if (empty($updates) && $note === '') {
        echo json_encode([
            'success' => true,
            'message' => 'No changes were detected.'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $conn->begin_transaction();

    if (!empty($updates)) {
        $sql = "UPDATE tb_feedback_submissions SET " . implode(', ', $updates) . " WHERE submission_id = ?";
        $updateTypes .= 'i';
        $updateValues[] = $submissionId;
        $updateStmt = $conn->prepare($sql);
        if (!$updateStmt) {
            throw new RuntimeException('Unable to update feedback submission.');
        }
        $bindParams = [];
        $bindParams[] = &$updateTypes;
        foreach ($updateValues as $index => $value) {
            $bindParams[] = &$updateValues[$index];
        }
        call_user_func_array([$updateStmt, 'bind_param'], $bindParams);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            throw new RuntimeException('Unable to update feedback submission.');
        }
        $updateStmt->close();
    }

    $activityAction = 'feedback_updated';
    if (isset($changes['assignment']) && count($changes) === 1 && $note === '') {
        $activityAction = 'feedback_assigned';
    } elseif (isset($changes['status'])) {
        $activityAction = 'feedback_status_updated';
    } elseif ($note !== '' && empty($changes)) {
        $activityAction = 'feedback_note_added';
    }

    if (!recordFeedbackActivity($conn, $submissionId, [
        'action' => $activityAction,
        'actor_id' => $actorId,
        'actor_name' => $actorName,
        'actor_role' => $actorRole,
        'from_status' => $changes['status']['from'] ?? (string)($current['status'] ?? ''),
        'to_status' => $changes['status']['to'] ?? $targetStatus,
        'note' => $note,
        'field_changes' => $changes
    ])) {
        throw new RuntimeException('Unable to write feedback activity history.');
    }

    logAuditEvent($conn, [
        'actor_id' => $actorId,
        'actor_name' => $actorName,
        'actor_role' => $actorRole,
        'action' => 'feedback_submission_updated',
        'entity_type' => 'feedback_submission',
        'entity_id' => (string)$submissionId,
        'details' => [
            'reference_no' => (string)($current['reference_no'] ?? ''),
            'changes' => $changes,
            'note' => $note,
            'assignee' => $assigneeSummary
        ]
    ]);

    if ($assignmentTaskPayload && function_exists('createWorkflowTask')) {
        $taskId = createWorkflowTask($conn, $assignmentTaskPayload);
        if (!$taskId && function_exists('recordSystemLog')) {
            recordSystemLog($conn, [
                'log_level' => 'warning',
                'log_category' => 'feedback',
                'event_code' => 'feedback_assignment_task_failed',
                'message' => 'Unable to create feedback follow-up task.',
                'context' => [
                    'submission_id' => $submissionId,
                    'assignee_id' => $assigneeSummary['user_id'] ?? '',
                    'assignee_role' => $assigneeSummary['user_role'] ?? ''
                ],
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'actor_role' => $actorRole
            ]);
        }
    }

    $conn->commit();

    if (getAppSettingBool($conn, 'notify_email_enabled', true)) {
        if ($assignmentNotification) {
            $queued = queueNotification(
                $conn,
                'email',
                $assignmentNotification['recipient'],
                $assignmentNotification['subject'],
                $assignmentNotification['message'],
                [
                    'source' => 'feedback_assignment',
                    'submission_id' => $submissionId,
                    'reference_no' => (string)($current['reference_no'] ?? ''),
                    'html_body' => $assignmentNotification['html_body']
                ]
            );
            if (!$queued && function_exists('recordSystemLog')) {
                recordSystemLog($conn, [
                    'log_level' => 'warning',
                    'log_category' => 'feedback_notification',
                    'event_code' => 'feedback_assignment_email_queue_failed',
                    'message' => 'Feedback assignment notification could not be queued.',
                    'context' => [
                        'submission_id' => $submissionId,
                        'recipient' => $assignmentNotification['recipient']
                    ],
                    'actor_id' => $actorId,
                    'actor_name' => $actorName,
                    'actor_role' => $actorRole
                ]);
            }
        }

        if (isset($changes['status'])) {
            $submitterEmail = trim((string)($current['email_address'] ?? ''));
            if ($submitterEmail !== '' && filter_var($submitterEmail, FILTER_VALIDATE_EMAIL) && in_array($targetStatus, ['reviewed', 'resolved', 'closed'], true)) {
                $statusLabel = getFeedbackStatusLabel($targetStatus);
                $resolutionText = $resolutionSummary !== '' ? ("\nResolution summary: " . $resolutionSummary) : '';
                $queued = queueNotification(
                    $conn,
                    'email',
                    $submitterEmail,
                    'Feedback update: ' . (string)($current['subject'] ?? 'Feedback submission'),
                    implode("\n", [
                        'Your feedback submission has been updated.',
                        'Reference: ' . (string)($current['reference_no'] ?? ''),
                        'Current status: ' . $statusLabel,
                        'Subject: ' . (string)($current['subject'] ?? '')
                    ]) . $resolutionText,
                    [
                        'source' => 'feedback_status_update',
                        'submission_id' => $submissionId,
                        'reference_no' => (string)($current['reference_no'] ?? ''),
                        'status' => $targetStatus,
                        'html_body' => '<p>Your feedback submission has been updated.</p>'
                            . '<p><strong>Reference:</strong> ' . htmlspecialchars((string)($current['reference_no'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
                            . '<strong>Current status:</strong> ' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '<br>'
                            . '<strong>Subject:</strong> ' . htmlspecialchars((string)($current['subject'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>'
                            . ($resolutionSummary !== '' ? '<p><strong>Resolution summary:</strong> ' . nl2br(htmlspecialchars($resolutionSummary, ENT_QUOTES, 'UTF-8')) . '</p>' : '')
                    ]
                );
                if (!$queued && function_exists('recordSystemLog')) {
                    recordSystemLog($conn, [
                        'log_level' => 'warning',
                        'log_category' => 'feedback_notification',
                        'event_code' => 'feedback_status_email_queue_failed',
                        'message' => 'Feedback submitter status update email could not be queued.',
                        'context' => [
                            'submission_id' => $submissionId,
                            'recipient' => $submitterEmail,
                            'status' => $targetStatus
                        ],
                        'actor_id' => $actorId,
                        'actor_name' => $actorName,
                        'actor_role' => $actorRole
                    ]);
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Feedback submission updated successfully.'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    $message = $e->getMessage();
    $statusCode = stripos($message, 'access denied') !== false ? 403 : (stripos($message, 'authentication required') !== false ? 401 : 500);
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
