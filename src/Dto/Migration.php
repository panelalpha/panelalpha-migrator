<?php

namespace WordpressMigrator\Dto;

/**
 * Class Migration
 *
 * @package WordpressMigrator\Dto
 *
 * Data Transfer Object for migration information.
 */
class Migration
{
    public string $type = 'info';
    public string $migrationId;
    public string $destinationUrl;
    public string $timestamp;
    public string $wordpressVersion;
    public string $phpVersion;
    public string $mysqlVersion;
    public string $siteUrl;
    public string $homeUrl;
    public ?string $sourceToken;
    public string $sourceTablePrefix = 'wp_';
    /** @var FileInfo[] */
    public array $files;
    /** @var TableInfo[] */
    public array $tables;
    public MigrationSummary $summary;

    /**
     * Migration constructor.
     *
     * @param string           $migrationId
     * @param string           $destinationUrl
     * @param string           $timestamp
     * @param string           $wordpressVersion
     * @param string           $phpVersion
     * @param string           $mysqlVersion
     * @param string           $siteUrl
     * @param string           $homeUrl
     * @param FileInfo[]       $files
     * @param TableInfo[]      $tables
     * @param MigrationSummary $summary
     * @param string|null      $sourceToken
     * @param string           $sourceTablePrefix
     */
    public function __construct(
        string $migrationId,
        string $destinationUrl,
        string $timestamp,
        string $wordpressVersion,
        string $phpVersion,
        string $mysqlVersion,
        string $siteUrl,
        string $homeUrl,
        array $files,
        array $tables,
        MigrationSummary $summary,
        ?string $sourceToken = null,
        string $sourceTablePrefix = 'wp_'
    ) {
        $this->migrationId = $migrationId;
        $this->destinationUrl = $destinationUrl;
        $this->timestamp = $timestamp;
        $this->wordpressVersion = $wordpressVersion;
        $this->phpVersion = $phpVersion;
        $this->mysqlVersion = $mysqlVersion;
        $this->siteUrl = $siteUrl;
        $this->homeUrl = $homeUrl;
        $this->files = $files;
        $this->tables = $tables;
        $this->summary = $summary;
        $this->sourceToken = $sourceToken;
        $this->sourceTablePrefix = $sourceTablePrefix;
    }
}
