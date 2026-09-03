<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\JiraSearchResult;

interface JiraClientInterface
{
    public function searchIssuesByRelease(string $release): JiraSearchResult;

    public function getLatestReleaseName(): string;

    public function getNextReleaseName(string $currentRelease): ?string;

    public function getReleaseUrlByName(string $release): ?string;
}
