<?php

declare(strict_types=1);

namespace Zolinga\Database;

use Zolinga\System\Events\{ListenerInterface, InstallScriptEvent};

/**
 * Takes care of .sql install/update scripts.
 *
 * The SQL file can contain multiple queries separated by a semicolon.
 * 
 * @author Daniel Sevcik <sevcik@webdevelopers.eu>
 * @date 2024-03-08
 */
class InstallSqlScript implements ListenerInterface
{

    public function onInstall(InstallScriptEvent $event): void
    {
        if ($this->runInstallScript($event->patchFile)) {
            $event->setStatus(InstallScriptEvent::STATUS_OK, 'Script executed successfully.');
        } else {
            $event->setStatus(InstallScriptEvent::STATUS_ERROR, 'Script execution failed.');
        }
        $event->stopPropagation();
    }


    private function runInstallScript(string $patchFile): bool
    {
        global $api;

        $sql = file_get_contents($patchFile);
        try {
            $api->db->multiQuery($sql);
        } catch (\Throwable $e) {
            $api->log->error("system.install", "Error executing SQL script: " . $e->getMessage());
            return false;
        }

        return true;
    }
}
