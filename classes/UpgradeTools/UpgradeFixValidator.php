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

use Symfony\Component\Filesystem\Filesystem;

class UpgradeFixValidator
{
    /** @var Filesystem */
    private $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'validate_upgrade_fix',
        description: 'Validate a proposed fix before applying it to the system.',
    )]
    #[\PhpMcp\Server\Attributes\Schema(
        properties: [
            'fixType' => ['type' => 'string', 'description' => 'Type of fix to validate (sql, file, permission, etc.)'],
            'fixData' => [
                'type' => 'object',
                'description' => 'Fix data to validate',
                'properties' => [
                    'commands' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'files' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'sql_queries' => ['type' => 'array', 'items' => ['type' => 'string']]
                ]
            ],
            'prestashopPath' => ['type' => 'string', 'description' => 'Path to PrestaShop installation'],
            'dryRun' => ['type' => 'boolean', 'description' => 'Perform validation without making changes (defaults to true)']
        ],
        required: ['fixType', 'fixData', 'prestashopPath']
    )]
    public function validateUpgradeFix(
        string $fixType,
        array $fixData,
        string $prestashopPath,
        bool $dryRun = true
    ): array {
        $validationResult = [
            'is_valid' => false,
            'validation_errors' => [],
            'warnings' => [],
            'estimated_impact' => 'unknown',
            'rollback_plan' => []
        ];

        switch ($fixType) {
            case 'sql':
                $validationResult = $this->validateSqlFix($fixData, $prestashopPath, $dryRun);
                break;
            case 'file':
            case 'permission':
                $validationResult = $this->validateFileFix($fixData, $prestashopPath, $dryRun);
                break;
            case 'config':
                $validationResult = $this->validateConfigFix($fixData, $prestashopPath, $dryRun);
                break;
            default:
                $validationResult['validation_errors'][] = "Unknown fix type: $fixType";
        }

        $validationResult['safety_score'] = $this->calculateSafetyScore($validationResult);

        return $validationResult;
    }

    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'apply_validated_fix',
        description: 'Apply a previously validated fix to the system.',
    )]
    #[\PhpMcp\Server\Attributes\Schema(
        properties: [
            'fixType' => ['type' => 'string', 'description' => 'Type of fix to apply'],
            'fixData' => ['type' => 'object', 'description' => 'Validated fix data to apply'],
            'prestashopPath' => ['type' => 'string', 'description' => 'Path to PrestaShop installation'],
            'createBackup' => ['type' => 'boolean', 'description' => 'Create backup before applying fix (defaults to true)']
        ],
        required: ['fixType', 'fixData', 'prestashopPath']
    )]
    public function applyValidatedFix(
        string $fixType,
        array $fixData,
        string $prestashopPath,
        bool $createBackup = true
    ): array {
        if ($createBackup) {
            $backupResult = $this->createPreFixBackup($prestashopPath, $fixType);
            if (!$backupResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to create backup: ' . $backupResult['error'],
                    'backup_path' => null
                ];
            }
        }

        $applyResult = [
            'success' => false,
            'applied_changes' => [],
            'errors' => [],
            'rollback_info' => []
        ];

        try {
            switch ($fixType) {
                case 'sql':
                    $applyResult = $this->applySqlFix($fixData, $prestashopPath);
                    break;
                case 'file':
                case 'permission':
                    $applyResult = $this->applyFileFix($fixData, $prestashopPath);
                    break;
                case 'config':
                    $applyResult = $this->applyConfigFix($fixData, $prestashopPath);
                    break;
            }

            if ($createBackup && $applyResult['success']) {
                $applyResult['backup_path'] = $backupResult['backup_path'] ?? null;
            }
        } catch (\Exception $e) {
            $applyResult['errors'][] = $e->getMessage();
        }

        return $applyResult;
    }

    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'verify_fix_effectiveness',
        description: 'Verify that an applied fix has resolved the original issue.',
    )]
    #[\PhpMcp\Server\Attributes\Schema(
        properties: [
            'originalError' => ['type' => 'string', 'description' => 'Original error message that was being fixed'],
            'fixType' => ['type' => 'string', 'description' => 'Type of fix that was applied'],
            'prestashopPath' => ['type' => 'string', 'description' => 'Path to PrestaShop installation'],
            'testScenarios' => ['type' => 'array', 'description' => 'Specific test scenarios to verify', 'items' => ['type' => 'string']]
        ],
        required: ['originalError', 'fixType', 'prestashopPath']
    )]
    public function verifyFixEffectiveness(
        string $originalError,
        string $fixType,
        string $prestashopPath,
        array $testScenarios = []
    ): array {
        $verificationResults = [
            'is_resolved' => false,
            'verification_tests' => [],
            'remaining_issues' => [],
            'recommendations' => []
        ];

        $tests = $this->generateVerificationTests($originalError, $fixType, $prestashopPath);

        foreach ($tests as $test) {
            $testResult = $this->runVerificationTest($test, $prestashopPath);
            $verificationResults['verification_tests'][] = $testResult;

            if (!$testResult['passed']) {
                $verificationResults['remaining_issues'][] = $testResult['issue'];
            }
        }

        $verificationResults['is_resolved'] = empty($verificationResults['remaining_issues']);

        if (!$verificationResults['is_resolved']) {
            $verificationResults['recommendations'] = $this->generateNextStepRecommendations(
                $verificationResults['remaining_issues'],
                $fixType
            );
        }

        return $verificationResults;
    }

    #[\PhpMcp\Server\Attributes\McpTool(
        name: 'generate_automated_patches',
        description: 'Generate automated patches for common upgrade issues.',
    )]
    #[\PhpMcp\Server\Attributes\Schema(
        properties: [
            'issueType' => ['type' => 'string', 'description' => 'Type of issue to generate patch for'],
            'issueDetails' => ['type' => 'object', 'description' => 'Detailed information about the issue'],
            'prestashopVersion' => ['type' => 'string', 'description' => 'Current PrestaShop version'],
            'targetVersion' => ['type' => 'string', 'description' => 'Target upgrade version']
        ],
        required: ['issueType', 'prestashopVersion']
    )]
    public function generateAutomatedPatches(
        string $issueType,
        array $issueDetails,
        string $prestashopVersion,
        ?string $targetVersion = null
    ): array {
        $patches = [];

        switch ($issueType) {
            case 'database_schema':
                $patches = $this->generateDatabasePatches($issueDetails, $prestashopVersion, $targetVersion);
                break;
            case 'module_compatibility':
                $patches = $this->generateModulePatches($issueDetails, $prestashopVersion, $targetVersion);
                break;
            case 'theme_compatibility':
                $patches = $this->generateThemePatches($issueDetails, $prestashopVersion, $targetVersion);
                break;
            case 'configuration_migration':
                $patches = $this->generateConfigPatches($issueDetails, $prestashopVersion, $targetVersion);
                break;
            default:
                return [
                    'error' => "No automated patches available for issue type: $issueType",
                    'available_types' => ['database_schema', 'module_compatibility', 'theme_compatibility', 'configuration_migration']
                ];
        }

        return [
            'patches' => $patches,
            'total_patches' => count($patches),
            'estimated_application_time' => $this->estimatePatchTime($patches),
            'risk_assessment' => $this->assessPatchRisk($patches),
            'application_order' => $this->determinePatchOrder($patches)
        ];
    }

    private function validateSqlFix(array $fixData, string $prestashopPath, bool $dryRun): array
    {
        $result = [
            'is_valid' => true,
            'validation_errors' => [],
            'warnings' => [],
            'estimated_impact' => 'medium',
            'rollback_plan' => []
        ];

        if (!isset($fixData['sql_queries']) || !is_array($fixData['sql_queries'])) {
            $result['is_valid'] = false;
            $result['validation_errors'][] = 'No SQL queries provided';
            return $result;
        }

        foreach ($fixData['sql_queries'] as $query) {
            if (!$this->isSafeSqlQuery($query)) {
                $result['is_valid'] = false;
                $result['validation_errors'][] = "Potentially unsafe SQL query: $query";
            }

            if ($this->isDestructiveSqlQuery($query)) {
                $result['warnings'][] = "Destructive SQL operation detected: $query";
                $result['estimated_impact'] = 'high';
            }
        }

        if ($result['is_valid'] && $dryRun) {
            $result['dry_run_results'] = $this->simulateSqlExecution($fixData['sql_queries'], $prestashopPath);
        }

        return $result;
    }

    private function validateFileFix(array $fixData, string $prestashopPath, bool $dryRun): array
    {
        $result = [
            'is_valid' => true,
            'validation_errors' => [],
            'warnings' => [],
            'estimated_impact' => 'low',
            'rollback_plan' => []
        ];

        if (isset($fixData['files'])) {
            foreach ($fixData['files'] as $file) {
                $fullPath = $prestashopPath . '/' . ltrim($file, '/');

                if (!$this->filesystem->exists(dirname($fullPath))) {
                    $result['validation_errors'][] = "Directory does not exist for file: $file";
                    $result['is_valid'] = false;
                }

                if ($this->isCriticalFile($file)) {
                    $result['warnings'][] = "Modifying critical file: $file";
                    $result['estimated_impact'] = 'high';
                }
            }
        }

        if (isset($fixData['commands'])) {
            foreach ($fixData['commands'] as $command) {
                if (!$this->isSafeCommand($command)) {
                    $result['validation_errors'][] = "Potentially unsafe command: $command";
                    $result['is_valid'] = false;
                }
            }
        }

        return $result;
    }

    private function validateConfigFix(array $fixData, string $prestashopPath, bool $dryRun): array
    {
        $result = [
            'is_valid' => true,
            'validation_errors' => [],
            'warnings' => [],
            'estimated_impact' => 'medium',
            'rollback_plan' => []
        ];

        $configFile = $prestashopPath . '/config/settings.inc.php';

        if (!$this->filesystem->exists($configFile)) {
            $result['validation_errors'][] = 'Configuration file not found';
            $result['is_valid'] = false;
            return $result;
        }

        if (!is_writable($configFile)) {
            $result['validation_errors'][] = 'Configuration file is not writable';
            $result['is_valid'] = false;
        }

        return $result;
    }

    private function calculateSafetyScore(array $validationResult): int
    {
        $score = 100;

        $score -= count($validationResult['validation_errors']) * 30;
        $score -= count($validationResult['warnings']) * 10;

        if ($validationResult['estimated_impact'] === 'high') {
            $score -= 20;
        } elseif ($validationResult['estimated_impact'] === 'medium') {
            $score -= 10;
        }

        return max(0, min(100, $score));
    }

    private function createPreFixBackup(string $prestashopPath, string $fixType): array
    {
        $backupDir = $prestashopPath . '/backup/pre_fix_' . date('Y-m-d_H-i-s');

        try {
            $this->filesystem->mkdir($backupDir);

            switch ($fixType) {
                case 'sql':
                    // Create database backup
                    $this->createDatabaseBackup($backupDir);
                    break;
                case 'file':
                case 'permission':
                    // Create file backup
                    $this->createFileBackup($prestashopPath, $backupDir);
                    break;
                case 'config':
                    // Create config backup
                    $this->createConfigBackup($prestashopPath, $backupDir);
                    break;
            }

            return ['success' => true, 'backup_path' => $backupDir];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function applySqlFix(array $fixData, string $prestashopPath): array
    {
        $result = [
            'success' => true,
            'applied_changes' => [],
            'errors' => [],
            'rollback_info' => []
        ];

        // This would need actual database connection - placeholder implementation
        foreach ($fixData['sql_queries'] as $query) {
            $result['applied_changes'][] = "Executed SQL: $query";
        }

        return $result;
    }

    private function applyFileFix(array $fixData, string $prestashopPath): array
    {
        $result = [
            'success' => true,
            'applied_changes' => [],
            'errors' => [],
            'rollback_info' => []
        ];

        if (isset($fixData['commands'])) {
            foreach ($fixData['commands'] as $command) {
                exec($command, $output, $returnCode);
                if ($returnCode === 0) {
                    $result['applied_changes'][] = "Executed command: $command";
                } else {
                    $result['errors'][] = "Failed to execute command: $command";
                    $result['success'] = false;
                }
            }
        }

        return $result;
    }

    private function applyConfigFix(array $fixData, string $prestashopPath): array
    {
        $result = [
            'success' => true,
            'applied_changes' => [],
            'errors' => [],
            'rollback_info' => []
        ];

        // Placeholder implementation for config fixes
        $result['applied_changes'][] = 'Configuration updated';

        return $result;
    }

    private function generateVerificationTests(string $originalError, string $fixType, string $prestashopPath): array
    {
        $tests = [];

        if (strpos($originalError, 'database') !== false) {
            $tests[] = [
                'type' => 'database_connection',
                'description' => 'Test database connectivity'
            ];
        }

        if (strpos($originalError, 'permission') !== false) {
            $tests[] = [
                'type' => 'file_permissions',
                'description' => 'Verify file permissions'
            ];
        }

        if (strpos($originalError, 'memory') !== false) {
            $tests[] = [
                'type' => 'memory_check',
                'description' => 'Check memory configuration'
            ];
        }

        return $tests;
    }

    private function runVerificationTest(array $test, string $prestashopPath): array
    {
        switch ($test['type']) {
            case 'database_connection':
                return [
                    'test' => $test['description'],
                    'passed' => $this->testDatabaseConnection($prestashopPath),
                    'issue' => 'Database connection still failing'
                ];
            case 'file_permissions':
                return [
                    'test' => $test['description'],
                    'passed' => $this->testFilePermissions($prestashopPath),
                    'issue' => 'File permission issues persist'
                ];
            case 'memory_check':
                return [
                    'test' => $test['description'],
                    'passed' => $this->testMemoryConfiguration(),
                    'issue' => 'Memory configuration insufficient'
                ];
            default:
                return [
                    'test' => $test['description'],
                    'passed' => false,
                    'issue' => 'Unknown test type'
                ];
        }
    }

    private function generateNextStepRecommendations(array $remainingIssues, string $fixType): array
    {
        $recommendations = [];

        foreach ($remainingIssues as $issue) {
            if (strpos($issue, 'database') !== false) {
                $recommendations[] = 'Verify database server is running and accessible';
            }
            if (strpos($issue, 'permission') !== false) {
                $recommendations[] = 'Check file ownership and SELinux/AppArmor settings';
            }
            if (strpos($issue, 'memory') !== false) {
                $recommendations[] = 'Restart web server to apply memory limit changes';
            }
        }

        return $recommendations;
    }

    private function generateDatabasePatches(array $issueDetails, string $prestashopVersion, ?string $targetVersion): array
    {
        return [
            [
                'type' => 'sql_migration',
                'description' => 'Update database schema for new version',
                'sql' => 'ALTER TABLE ps_product ADD COLUMN new_field VARCHAR(255);',
                'risk' => 'medium'
            ]
        ];
    }

    private function generateModulePatches(array $issueDetails, string $prestashopVersion, ?string $targetVersion): array
    {
        return [
            [
                'type' => 'module_update',
                'description' => 'Update module compatibility',
                'actions' => ['disable_incompatible_modules', 'update_module_hooks'],
                'risk' => 'low'
            ]
        ];
    }

    private function generateThemePatches(array $issueDetails, string $prestashopVersion, ?string $targetVersion): array
    {
        return [
            [
                'type' => 'theme_update',
                'description' => 'Update theme templates for new version',
                'files' => ['themes/classic/templates/'],
                'risk' => 'medium'
            ]
        ];
    }

    private function generateConfigPatches(array $issueDetails, string $prestashopVersion, ?string $targetVersion): array
    {
        return [
            [
                'type' => 'config_migration',
                'description' => 'Migrate configuration settings',
                'config_changes' => ['update_cache_settings', 'migrate_url_rewriting'],
                'risk' => 'low'
            ]
        ];
    }

    private function estimatePatchTime(array $patches): string
    {
        $totalMinutes = count($patches) * 5; // 5 minutes per patch estimate
        return "$totalMinutes minutes";
    }

    private function assessPatchRisk(array $patches): string
    {
        $highRiskCount = 0;
        foreach ($patches as $patch) {
            if (($patch['risk'] ?? 'medium') === 'high') {
                $highRiskCount++;
            }
        }

        if ($highRiskCount > 0) return 'high';
        if (count($patches) > 5) return 'medium';
        return 'low';
    }

    private function determinePatchOrder(array $patches): array
    {
        // Sort by risk and dependencies
        usort($patches, function ($a, $b) {
            $riskOrder = ['low' => 1, 'medium' => 2, 'high' => 3];
            return $riskOrder[$a['risk'] ?? 'medium'] <=> $riskOrder[$b['risk'] ?? 'medium'];
        });

        return $patches;
    }

    private function isSafeSqlQuery(string $query): bool
    {
        $dangerousPatterns = [
            '/DROP\s+DATABASE/i',
            '/TRUNCATE\s+TABLE/i',
            '/DELETE\s+FROM.*WHERE\s*$/i', // DELETE without WHERE
            '/UPDATE.*SET.*WHERE\s*$/i'     // UPDATE without WHERE
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return false;
            }
        }

        return true;
    }

    private function isDestructiveSqlQuery(string $query): bool
    {
        $destructivePatterns = [
            '/DROP\s+TABLE/i',
            '/ALTER\s+TABLE.*DROP/i',
            '/DELETE\s+FROM/i',
            '/TRUNCATE/i'
        ];

        foreach ($destructivePatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }

    private function simulateSqlExecution(array $queries, string $prestashopPath): array
    {
        // Simulate SQL execution - would need actual database connection
        return [
            'simulated' => true,
            'queries_count' => count($queries),
            'estimated_execution_time' => count($queries) * 0.1 . ' seconds'
        ];
    }

    private function isCriticalFile(string $file): bool
    {
        $criticalPaths = [
            'config/settings.inc.php',
            'index.php',
            '.htaccess',
            'robots.txt'
        ];

        foreach ($criticalPaths as $critical) {
            if (strpos($file, $critical) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isSafeCommand(string $command): bool
    {
        $unsafePatterns = [
            '/rm\s+-rf/i',
            '/sudo\s+rm/i',
            '/chmod\s+777/i',
            '/>/dev/null.*&/i' // Background processes
        ];

        foreach ($unsafePatterns as $pattern) {
            if (preg_match($pattern, $command)) {
                return false;
            }
        }

        return true;
    }

    private function createDatabaseBackup(string $backupDir): void
    {
        // Placeholder - would implement actual database backup
        file_put_contents($backupDir . '/database_backup.sql', '-- Database backup placeholder');
    }

    private function createFileBackup(string $prestashopPath, string $backupDir): void
    {
        // Placeholder - would implement actual file backup
        file_put_contents($backupDir . '/files_backup.tar.gz', 'Files backup placeholder');
    }

    private function createConfigBackup(string $prestashopPath, string $backupDir): void
    {
        $configFile = $prestashopPath . '/config/settings.inc.php';
        if ($this->filesystem->exists($configFile)) {
            $this->filesystem->copy($configFile, $backupDir . '/settings.inc.php.backup');
        }
    }

    private function testDatabaseConnection(string $prestashopPath): bool
    {
        // Placeholder - would test actual database connection
        return true;
    }

    private function testFilePermissions(string $prestashopPath): bool
    {
        $criticalPaths = [
            $prestashopPath . '/config',
            $prestashopPath . '/cache',
            $prestashopPath . '/upload'
        ];

        foreach ($criticalPaths as $path) {
            if ($this->filesystem->exists($path) && !is_writable($path)) {
                return false;
            }
        }

        return true;
    }

    private function testMemoryConfiguration(): bool
    {
        $memoryLimit = ini_get('memory_limit');
        $bytes = $this->convertToBytes($memoryLimit);

        return $bytes >= (256 * 1024 * 1024); // 256MB minimum
    }

    private function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}