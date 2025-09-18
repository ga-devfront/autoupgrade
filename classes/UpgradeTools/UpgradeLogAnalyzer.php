<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace PrestaShop\Module\AutoUpgrade\UpgradeTools;

class UpgradeLogAnalyzer
{
    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'analyze_upgrade_logs',
        description: 'Analyze upgrade logs to identify errors and potential issues during upgrade process.',
    )]
    #[\PhpMcp\Server\Attributes\Schema(
        properties: [
            'logFilePath' => ['type' => 'string', 'description' => 'Path to the upgrade log file to analyze'],
            'logContent' => ['type' => 'string', 'description' => 'Direct log content to analyze (alternative to file path)'],
            'includeWarnings' => ['type' => 'boolean', 'description' => 'Include warnings in analysis (defaults to true)']
        ],
        required: []
    )]
    public function analyzeUpgradeLogs(
        ?string $logFilePath = null,
        ?string $logContent = null,
        bool $includeWarnings = true
    ): array {
        if ($logFilePath && file_exists($logFilePath)) {
            $content = file_get_contents($logFilePath);
        } elseif ($logContent) {
            $content = $logContent;
        } else {
            return ['error' => 'No log file path or content provided'];
        }

        if (!$content) {
            return ['error' => 'Could not read log content'];
        }

        $errors = $this->extractErrors($content);
        $warnings = $includeWarnings ? $this->extractWarnings($content) : [];
        $criticalIssues = $this->extractCriticalIssues($content);
        $summary = $this->generateSummary($errors, $warnings, $criticalIssues);

        return [
            'summary' => $summary,
            'errors' => $errors,
            'warnings' => $warnings,
            'critical_issues' => $criticalIssues,
            'suggested_fixes' => $this->suggestFixes($errors, $criticalIssues)
        ];
    }

    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'detect_upgrade_error_patterns',
        description: 'Detect common upgrade error patterns and categorize them.',
    )]
    #[\PhpMcp\Server\Attributes\Schema(
        properties: [
            'logContent' => ['type' => 'string', 'description' => 'Log content to analyze for error patterns'],
            'errorType' => ['type' => 'string', 'description' => 'Specific error type to focus on (database, files, permissions, etc.)']
        ],
        required: ['logContent']
    )]
    public function detectUpgradeErrorPatterns(string $logContent, ?string $errorType = null): array
    {
        $patterns = $this->getErrorPatterns();
        $detectedErrors = [];

        foreach ($patterns as $category => $categoryPatterns) {
            if ($errorType && $category !== $errorType) {
                continue;
            }

            foreach ($categoryPatterns as $pattern) {
                if (preg_match_all($pattern['regex'], $logContent, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $detectedErrors[] = [
                            'category' => $category,
                            'type' => $pattern['type'],
                            'message' => trim($match[0]),
                            'line_position' => $this->getLineNumber($logContent, $match[1]),
                            'severity' => $pattern['severity'],
                            'description' => $pattern['description'],
                            'potential_fix' => $pattern['fix'] ?? null
                        ];
                    }
                }
            }
        }

        return [
            'detected_errors' => $detectedErrors,
            'error_count_by_category' => $this->countErrorsByCategory($detectedErrors),
            'recommendations' => $this->generateRecommendations($detectedErrors)
        ];
    }

    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'generate_upgrade_fix_suggestions',
        description: 'Generate specific fix suggestions based on detected upgrade errors.',
    )]
    #[\PhpMcp\Server\Attributes\Schema(
        properties: [
            'errorData' => [
                'type' => 'array',
                'description' => 'Error data from analysis to generate fixes for',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'message' => ['type' => 'string']
                    ]
                ]
            ],
            'includeAutomaticFixes' => ['type' => 'boolean', 'description' => 'Include automatic fix scripts (defaults to false)']
        ],
        required: ['errorData']
    )]
    public function generateUpgradeFixSuggestions(array $errorData, bool $includeAutomaticFixes = false): array
    {
        $suggestions = [];

        foreach ($errorData as $error) {
            $fix = $this->generateSpecificFix($error);
            if ($fix) {
                $suggestions[] = $fix;
            }
        }

        return [
            'fix_suggestions' => $suggestions,
            'automatic_fixes_available' => $includeAutomaticFixes,
            'manual_intervention_required' => $this->requiresManualIntervention($errorData),
            'execution_order' => $this->determineFxOrder($suggestions)
        ];
    }

    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'validate_upgrade_prerequisites',
        description: 'Validate prerequisites before upgrade to prevent common issues.',
    )]
    #[\PhpMcp\Server\Attributes\Schema(
        properties: [
            'prestashopPath' => ['type' => 'string', 'description' => 'Path to PrestaShop installation'],
            'targetVersion' => ['type' => 'string', 'description' => 'Target PrestaShop version for upgrade']
        ],
        required: ['prestashopPath']
    )]
    public function validateUpgradePrerequisites(string $prestashopPath, ?string $targetVersion = null): array
    {
        $checks = [];

        $checks['file_permissions'] = $this->checkFilePermissions($prestashopPath);
        $checks['disk_space'] = $this->checkDiskSpace($prestashopPath);
        $checks['database_access'] = $this->checkDatabaseAccess($prestashopPath);
        $checks['php_requirements'] = $this->checkPhpRequirements($targetVersion);
        $checks['backup_status'] = $this->checkBackupStatus($prestashopPath);
        $checks['module_compatibility'] = $this->checkModuleCompatibility($prestashopPath, $targetVersion);

        $overallStatus = $this->calculateOverallStatus($checks);

        return [
            'overall_status' => $overallStatus,
            'checks' => $checks,
            'blocking_issues' => $this->getBlockingIssues($checks),
            'recommendations' => $this->getPreUpgradeRecommendations($checks)
        ];
    }

    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'generate_rollback_plan',
        description: 'Generate a rollback plan based on upgrade logs and current state.',
    )]
    #[\PhpMcp\Server\Attributes\Schema(
        properties: [
            'prestashopPath' => ['type' => 'string', 'description' => 'Path to PrestaShop installation'],
            'backupPath' => ['type' => 'string', 'description' => 'Path to backup files'],
            'upgradeLogPath' => ['type' => 'string', 'description' => 'Path to upgrade log file']
        ],
        required: ['prestashopPath']
    )]
    public function generateRollbackPlan(string $prestashopPath, ?string $backupPath = null, ?string $upgradeLogPath = null): array
    {
        $rollbackSteps = [];

        if ($upgradeLogPath && file_exists($upgradeLogPath)) {
            $logAnalysis = $this->analyzeUpgradeLogs($upgradeLogPath);
            $rollbackSteps[] = [
                'step' => 'analyze_failure_point',
                'description' => 'Identify where the upgrade failed',
                'details' => $logAnalysis['summary']
            ];
        }

        $rollbackSteps[] = [
            'step' => 'stop_services',
            'description' => 'Stop web server and disable maintenance mode if needed',
            'commands' => $this->getServiceStopCommands()
        ];

        if ($backupPath && is_dir($backupPath)) {
            $rollbackSteps[] = [
                'step' => 'restore_files',
                'description' => 'Restore files from backup',
                'backup_path' => $backupPath,
                'estimated_time' => $this->estimateRestoreTime($backupPath)
            ];
        }

        $rollbackSteps[] = [
            'step' => 'restore_database',
            'description' => 'Restore database from backup',
            'sql_script' => $this->generateDatabaseRollbackScript($prestashopPath)
        ];

        $rollbackSteps[] = [
            'step' => 'clear_cache',
            'description' => 'Clear all caches after rollback',
            'cache_paths' => $this->getCachePaths($prestashopPath)
        ];

        return [
            'rollback_steps' => $rollbackSteps,
            'estimated_total_time' => $this->calculateTotalRollbackTime($rollbackSteps),
            'risk_assessment' => $this->assessRollbackRisk($prestashopPath, $backupPath),
            'verification_steps' => $this->getVerificationSteps()
        ];
    }

    private function extractErrors(string $content): array
    {
        $errors = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/\b(ERROR|CRITICAL|EMERGENCY)\b/i', $line)) {
                $errors[] = [
                    'line' => $lineNumber + 1,
                    'message' => trim($line),
                    'timestamp' => $this->extractTimestamp($line)
                ];
            }
        }

        return $errors;
    }

    private function extractWarnings(string $content): array
    {
        $warnings = [];
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/\bWARNING\b/i', $line)) {
                $warnings[] = [
                    'line' => $lineNumber + 1,
                    'message' => trim($line),
                    'timestamp' => $this->extractTimestamp($line)
                ];
            }
        }

        return $warnings;
    }

    private function extractCriticalIssues(string $content): array
    {
        $critical = [];
        $patterns = [
            'database_error' => '/database|sql|mysql|connection.*failed/i',
            'file_permission' => '/permission.*denied|cannot.*write|file.*not.*writable/i',
            'memory_error' => '/memory.*exhausted|fatal.*error.*memory/i',
            'timeout_error' => '/timeout|time.*limit.*exceeded/i'
        ];

        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            foreach ($patterns as $type => $pattern) {
                if (preg_match($pattern, $line)) {
                    $critical[] = [
                        'type' => $type,
                        'line' => $lineNumber + 1,
                        'message' => trim($line),
                        'timestamp' => $this->extractTimestamp($line)
                    ];
                }
            }
        }

        return $critical;
    }

    private function generateSummary(array $errors, array $warnings, array $criticalIssues): array
    {
        return [
            'total_errors' => count($errors),
            'total_warnings' => count($warnings),
            'total_critical' => count($criticalIssues),
            'status' => count($errors) > 0 || count($criticalIssues) > 0 ? 'failed' : 'warning',
            'most_common_issues' => $this->getMostCommonIssues(array_merge($errors, $criticalIssues))
        ];
    }

    private function suggestFixes(array $errors, array $criticalIssues): array
    {
        $fixes = [];
        $allIssues = array_merge($errors, $criticalIssues);

        foreach ($allIssues as $issue) {
            if (isset($issue['type'])) {
                $fix = $this->getFixForIssueType($issue['type']);
                if ($fix) {
                    $fixes[] = $fix;
                }
            }
        }

        return array_unique($fixes, SORT_REGULAR);
    }

    private function getErrorPatterns(): array
    {
        return [
            'database' => [
                [
                    'regex' => '/Table.*doesn\'t exist|Unknown column|SQL syntax error/i',
                    'type' => 'schema_mismatch',
                    'severity' => 'critical',
                    'description' => 'Database schema inconsistency',
                    'fix' => 'Run database repair or restore from backup'
                ],
                [
                    'regex' => '/Connection refused|Access denied.*database/i',
                    'type' => 'connection_error',
                    'severity' => 'critical',
                    'description' => 'Database connection failure',
                    'fix' => 'Check database credentials and service status'
                ]
            ],
            'files' => [
                [
                    'regex' => '/Permission denied|Cannot write|File not writable/i',
                    'type' => 'permission_error',
                    'severity' => 'critical',
                    'description' => 'File permission issues',
                    'fix' => 'Set proper file permissions (755 for directories, 644 for files)'
                ],
                [
                    'regex' => '/No space left|Disk full/i',
                    'type' => 'disk_space',
                    'severity' => 'critical',
                    'description' => 'Insufficient disk space',
                    'fix' => 'Free up disk space or expand storage'
                ]
            ],
            'memory' => [
                [
                    'regex' => '/Fatal error.*memory.*exhausted/i',
                    'type' => 'memory_limit',
                    'severity' => 'critical',
                    'description' => 'PHP memory limit exceeded',
                    'fix' => 'Increase PHP memory_limit in php.ini'
                ]
            ]
        ];
    }

    private function getLineNumber(string $content, int $position): int
    {
        return substr_count(substr($content, 0, $position), "\n") + 1;
    }

    private function countErrorsByCategory(array $errors): array
    {
        $counts = [];
        foreach ($errors as $error) {
            $category = $error['category'];
            $counts[$category] = ($counts[$category] ?? 0) + 1;
        }
        return $counts;
    }

    private function generateRecommendations(array $errors): array
    {
        $recommendations = [];
        $categories = array_unique(array_column($errors, 'category'));

        foreach ($categories as $category) {
            switch ($category) {
                case 'database':
                    $recommendations[] = 'Verify database integrity and connection settings';
                    break;
                case 'files':
                    $recommendations[] = 'Check file permissions and disk space';
                    break;
                case 'memory':
                    $recommendations[] = 'Increase PHP memory limit and optimize server resources';
                    break;
            }
        }

        return $recommendations;
    }

    private function generateSpecificFix(array $error): ?array
    {
        $fixes = [
            'database_error' => [
                'type' => 'sql_repair',
                'description' => 'Repair database tables',
                'commands' => ['REPAIR TABLE table_name;', 'CHECK TABLE table_name;']
            ],
            'file_permission' => [
                'type' => 'permission_fix',
                'description' => 'Fix file permissions',
                'commands' => ['chmod 755 directories/', 'chmod 644 files']
            ],
            'memory_error' => [
                'type' => 'memory_increase',
                'description' => 'Increase PHP memory limit',
                'config' => 'memory_limit = 512M'
            ]
        ];

        $type = $error['type'] ?? $error['category'] ?? 'unknown';
        return $fixes[$type] ?? null;
    }

    private function requiresManualIntervention(array $errorData): bool
    {
        $manualTypes = ['schema_mismatch', 'custom_module_conflict', 'theme_compatibility'];

        foreach ($errorData as $error) {
            if (in_array($error['type'] ?? '', $manualTypes)) {
                return true;
            }
        }

        return false;
    }

    private function determineFxOrder(array $suggestions): array
    {
        $order = ['database', 'permissions', 'memory', 'files', 'cache'];
        $orderedSuggestions = [];

        foreach ($order as $priority) {
            foreach ($suggestions as $suggestion) {
                if (strpos($suggestion['type'] ?? '', $priority) !== false) {
                    $orderedSuggestions[] = $suggestion;
                }
            }
        }

        return $orderedSuggestions;
    }

    private function checkFilePermissions(string $prestashopPath): array
    {
        $result = ['status' => 'ok', 'issues' => []];

        $criticalPaths = [
            $prestashopPath . '/config',
            $prestashopPath . '/cache',
            $prestashopPath . '/log',
            $prestashopPath . '/upload',
            $prestashopPath . '/download'
        ];

        foreach ($criticalPaths as $path) {
            if (file_exists($path) && !is_writable($path)) {
                $result['status'] = 'error';
                $result['issues'][] = "Path not writable: $path";
            }
        }

        return $result;
    }

    private function checkDiskSpace(string $prestashopPath): array
    {
        $freeBytes = disk_free_space($prestashopPath);
        $totalBytes = disk_total_space($prestashopPath);
        $freeGB = $freeBytes / (1024 * 1024 * 1024);

        return [
            'status' => $freeGB > 1 ? 'ok' : 'warning',
            'free_space_gb' => round($freeGB, 2),
            'total_space_gb' => round($totalBytes / (1024 * 1024 * 1024), 2),
            'recommendation' => $freeGB < 1 ? 'Free up at least 1GB of disk space' : 'Sufficient disk space available'
        ];
    }

    private function checkDatabaseAccess(string $prestashopPath): array
    {
        $configFile = $prestashopPath . '/config/settings.inc.php';

        if (!file_exists($configFile)) {
            return ['status' => 'error', 'message' => 'Config file not found'];
        }

        return ['status' => 'ok', 'message' => 'Database configuration file found'];
    }

    private function checkPhpRequirements(?string $targetVersion): array
    {
        $phpVersion = PHP_VERSION;
        $requirements = [
            '8.0' => '7.4.0',
            '8.1' => '8.0.0',
            '9.0' => '8.1.0'
        ];

        $minRequired = $requirements[$targetVersion] ?? '7.4.0';
        $isCompatible = version_compare($phpVersion, $minRequired, '>=');

        return [
            'status' => $isCompatible ? 'ok' : 'error',
            'current_version' => $phpVersion,
            'required_version' => $minRequired,
            'compatible' => $isCompatible
        ];
    }

    private function checkBackupStatus(string $prestashopPath): array
    {
        $backupDir = $prestashopPath . '/backup';
        $hasRecentBackup = false;

        if (is_dir($backupDir)) {
            $files = glob($backupDir . '/*');
            foreach ($files as $file) {
                if (filemtime($file) > (time() - 86400)) { // 24 hours
                    $hasRecentBackup = true;
                    break;
                }
            }
        }

        return [
            'status' => $hasRecentBackup ? 'ok' : 'warning',
            'has_recent_backup' => $hasRecentBackup,
            'recommendation' => $hasRecentBackup ? 'Recent backup found' : 'Create a fresh backup before upgrade'
        ];
    }

    private function checkModuleCompatibility(string $prestashopPath, ?string $targetVersion): array
    {
        return [
            'status' => 'info',
            'message' => 'Module compatibility check requires manual verification',
            'recommendation' => 'Review installed modules for compatibility with target version'
        ];
    }

    private function calculateOverallStatus(array $checks): string
    {
        $hasError = false;
        $hasWarning = false;

        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $hasError = true;
            } elseif ($check['status'] === 'warning') {
                $hasWarning = true;
            }
        }

        if ($hasError) return 'not_ready';
        if ($hasWarning) return 'ready_with_warnings';
        return 'ready';
    }

    private function getBlockingIssues(array $checks): array
    {
        $blocking = [];
        foreach ($checks as $name => $check) {
            if ($check['status'] === 'error') {
                $blocking[] = $name;
            }
        }
        return $blocking;
    }

    private function getPreUpgradeRecommendations(array $checks): array
    {
        $recommendations = ['Create a complete backup before starting upgrade'];

        foreach ($checks as $name => $check) {
            if (isset($check['recommendation'])) {
                $recommendations[] = $check['recommendation'];
            }
        }

        return $recommendations;
    }

    private function getServiceStopCommands(): array
    {
        return [
            'sudo systemctl stop apache2',
            'sudo systemctl stop nginx',
            'php bin/console maintenance:enable'
        ];
    }

    private function estimateRestoreTime(string $backupPath): string
    {
        $size = $this->getDirectorySize($backupPath);
        $sizeGB = $size / (1024 * 1024 * 1024);
        $estimatedMinutes = max(5, $sizeGB * 2); // Rough estimate

        return round($estimatedMinutes) . ' minutes';
    }

    private function generateDatabaseRollbackScript(string $prestashopPath): string
    {
        return "-- Database rollback script\n-- Restore from backup SQL file\n-- mysql -u username -p database_name < backup.sql";
    }

    private function getCachePaths(string $prestashopPath): array
    {
        return [
            $prestashopPath . '/var/cache',
            $prestashopPath . '/cache',
            $prestashopPath . '/app/cache'
        ];
    }

    private function calculateTotalRollbackTime(array $steps): string
    {
        return '30-60 minutes'; // Rough estimate
    }

    private function assessRollbackRisk(string $prestashopPath, ?string $backupPath): string
    {
        if (!$backupPath || !is_dir($backupPath)) {
            return 'high'; // No backup available
        }

        return 'low'; // Backup available
    }

    private function getVerificationSteps(): array
    {
        return [
            'Check website accessibility',
            'Verify admin panel login',
            'Test critical functionalities',
            'Check for any error logs'
        ];
    }

    private function extractTimestamp(string $line): ?string
    {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function getMostCommonIssues(array $issues): array
    {
        $types = [];
        foreach ($issues as $issue) {
            $type = $issue['type'] ?? 'unknown';
            $types[$type] = ($types[$type] ?? 0) + 1;
        }

        arsort($types);
        return array_slice($types, 0, 3, true);
    }

    private function getFixForIssueType(string $type): ?array
    {
        $fixes = [
            'database_error' => [
                'title' => 'Database Error Fix',
                'description' => 'Check database connection and repair tables',
                'priority' => 'high'
            ],
            'file_permission' => [
                'title' => 'File Permission Fix',
                'description' => 'Set correct file permissions',
                'priority' => 'high'
            ],
            'memory_error' => [
                'title' => 'Memory Error Fix',
                'description' => 'Increase PHP memory limit',
                'priority' => 'medium'
            ]
        ];

        return $fixes[$type] ?? null;
    }

    private function getDirectorySize(string $path): int
    {
        $size = 0;
        if (is_dir($path)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $size += $file->getSize();
            }
        }
        return $size;
    }
}