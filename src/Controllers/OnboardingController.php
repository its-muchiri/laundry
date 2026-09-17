<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Request;
use Laundry\Core\Response;

/**
 * Operator onboarding — creates the operator's user record (if not already
 * signed up) and its initial KYC document submissions, gating on the
 * shared KYC/Verification Pipeline (see
 * planning/00-portfolio/shared-architecture.md). Per this platform's Tier
 * 1/2 trust configuration (see shared-architecture.md's trust-tiering
 * table), laundry.co.ke operators need at minimum national ID; commercial
 * launderettes additionally submit business registration.
 */
final class OnboardingController
{
    public function submit(Request $request): void
    {
        $db = Database::connection();
        $documents = $request->input('documents', []); // [{ document_type, file_reference }]

        if (empty($documents)) {
            Response::error('At least one KYC document is required to onboard', 422);
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO kyc_documents (user_id, document_type, file_reference, verification_status)
             VALUES (:user_id, :document_type, :file_reference, "pending")'
        );

        $submitted = [];
        foreach ($documents as $document) {
            $stmt->execute([
                'user_id' => $request->user['id'] ?? null,
                'document_type' => $document['document_type'],
                'file_reference' => $document['file_reference'],
            ]);
            $submitted[] = (int) $db->lastInsertId();
        }

        // Operator remains "pending_verification" (see users.status enum)
        // until a Platform Admin approves every submitted document — see
        // this platform's user-flows.md Primary Supply-Side Journey step 3.
        $stmt = $db->prepare('UPDATE users SET status = "pending_verification" WHERE id = :id');
        $stmt->execute(['id' => $request->user['id'] ?? null]);

        Response::json(['kyc_document_ids' => $submitted, 'status' => 'pending_verification'], 201);
    }
}
