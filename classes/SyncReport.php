<?php

/**
 * @file classes/SyncReport.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo / Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SyncReport
 *
 * @brief Collects per-contributor outcomes and aggregate counts produced by a
 *   sync run (single contributor or bulk). Used both for editor feedback and
 *   for the downloadable bulk-sync report. Never stores passwords or tokens.
 */

namespace APP\plugins\generic\contributorUserSync\classes;

class SyncReport
{
    // Outcome codes. Each maps to a locale key plugins.generic.contributorUserSync.outcome.<code>.
    public const MATCHED_USER = 'matchedUser';
    public const LINKED = 'linked';
    public const ORCID_SYNCED = 'orcidSynced';
    public const ORCID_SKIPPED_EXISTING = 'orcidSkippedExisting';
    public const ORCID_SKIPPED_UNVERIFIED = 'orcidSkippedUnverified';
    public const USER_CREATED = 'userCreated';
    public const INVITATION_SENT = 'invitationSent';
    public const SKIPPED_NO_EMAIL = 'skippedNoEmail';
    public const SKIPPED_NO_USER = 'skippedNoUser';
    public const SKIPPED_NO_VERIFIED_ORCID = 'skippedNoVerifiedOrcid';
    public const SKIPPED_INELIGIBLE_ROLE = 'skippedIneligibleRole';
    public const ERROR = 'error';

    /** @var int Distinct contributors scanned */
    public int $scanned = 0;

    /** @var array<string,int> outcomeCode => count */
    public array $counts = [];

    /** @var array<int,array> Per-contributor rows: contributorId, submissionId, name, email, outcomes[], detail */
    public array $rows = [];

    /** @var array Outcomes accumulated for the contributor currently being processed */
    private array $currentOutcomes = [];

    /** @var string|null Human-readable detail for the current contributor */
    private ?string $currentDetail = null;

    /**
     * Begin recording outcomes for a contributor. Increments the scanned count.
     */
    public function beginContributor(): void
    {
        $this->scanned++;
        $this->currentOutcomes = [];
        $this->currentDetail = null;
    }

    /**
     * Record an outcome for the contributor currently being processed.
     */
    public function record(string $outcome, ?string $detail = null): void
    {
        $this->currentOutcomes[] = $outcome;
        $this->counts[$outcome] = ($this->counts[$outcome] ?? 0) + 1;
        if ($detail !== null) {
            $this->currentDetail = $detail;
        }
    }

    /**
     * Flush the current contributor's outcomes into a report row.
     */
    public function endContributor(int $contributorId, int $submissionId, string $name, string $email): void
    {
        $this->rows[] = [
            'contributorId' => $contributorId,
            'submissionId' => $submissionId,
            'name' => $name,
            'email' => $email,
            'outcomes' => $this->currentOutcomes,
            'detail' => $this->currentDetail,
        ];
    }

    /**
     * Outcomes recorded so far for the in-progress contributor (for inline feedback).
     */
    public function currentOutcomes(): array
    {
        return $this->currentOutcomes;
    }

    public function count(string $outcome): int
    {
        return $this->counts[$outcome] ?? 0;
    }

    /**
     * Aggregate summary suitable for assigning to a template.
     */
    public function summary(): array
    {
        return [
            'scanned' => $this->scanned,
            'matched' => $this->count(self::MATCHED_USER),
            'created' => $this->count(self::USER_CREATED),
            'invited' => $this->count(self::INVITATION_SENT),
            'orcidSynced' => $this->count(self::ORCID_SYNCED),
            'skippedNoEmail' => $this->count(self::SKIPPED_NO_EMAIL),
            'skippedNoUser' => $this->count(self::SKIPPED_NO_USER),
            'skippedNoVerifiedOrcid' => $this->count(self::SKIPPED_NO_VERIFIED_ORCID),
            'errors' => $this->count(self::ERROR),
        ];
    }

    /**
     * Render the per-contributor rows as CSV (no sensitive fields).
     */
    public function toCsv(): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['Submission ID', 'Contributor ID', 'Name', 'Email', 'Outcomes', 'Detail']);
        foreach ($this->rows as $row) {
            fputcsv($fh, [
                $row['submissionId'],
                $row['contributorId'],
                $row['name'],
                $row['email'],
                implode('; ', $row['outcomes']),
                $row['detail'] ?? '',
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }
}
