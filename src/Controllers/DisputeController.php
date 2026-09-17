<?php

namespace Laundry\Controllers;

use Laundry\Config\Database;
use Laundry\Core\Auth;
use Laundry\Core\Request;
use Laundry\Core\Response;

/**
 * Disputes live in the shared `disputes` table. This platform's category
 * taxonomy (see database/schema.sql comment and
 * planning/01-laundry-co-ke/database-schema.md): item_damaged, item_lost,
 * poor_quality, price_disagreement, other.
 */
final class DisputeController
{
    public function store(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO disputes (booking_id, raised_by, category, description, evidence_urls, status, created_at)
             VALUES (:booking_id, :raised_by, :category, :description, :evidence_urls, \'open\', NOW())'
        );
        $stmt->execute([
            'booking_id' => $request->params['id'],
            'raised_by' => $user['id'],
            'category' => $request->input('category'),
            'description' => $request->input('description'),
            'evidence_urls' => json_encode($request->input('evidence_urls', [])),
        ]);

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'open'], 201);
    }

    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->query('SELECT * FROM disputes WHERE status IN (\'open\', \'under_review\') ORDER BY created_at ASC');

        Response::json($stmt->fetchAll());
    }

    public function resolve(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        // TODO: per planning/01-laundry-co-ke/user-flows.md's dispute flow —
        // dispatcher-level resolution below the (unconfirmed) KES 1,000
        // threshold, else escalate to Platform Admin. Role check (dispatcher
        // vs platform admin) is not enforced here yet — see
        // OperatorController-style account_type checks for the pattern to
        // extend this with once the admin console is built.
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE disputes SET status = :status, resolved_by = :resolved_by, resolution_notes = :notes, resolved_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $request->input('status'),
            'resolved_by' => $user['id'],
            'notes' => $request->input('resolution_notes'),
            'id' => $request->params['id'],
        ]);

        Response::json(['id' => (int) $request->params['id'], 'status' => $request->input('status')]);
    }
}
