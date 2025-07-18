<?php

namespace App\Console\Commands\Updates;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

class Update0961
{
    protected $file1 = 'https://raw.githubusercontent.com/nemerald-voip/fusionpbx/master/app/switch/resources/scripts/app/voicemail/index.lua';
    protected $filePath1;
    protected $filePath2;

    public function __construct()
    {
        $this->filePath1 = base_path('public/app/switch/resources/scripts/app/voicemail/index.lua');
        $this->filePath2 = '/usr/share/freeswitch/scripts/app/voicemail/index.lua';
    }

    /**
     * Apply update steps.
     *
     * @return bool
     */
    public function apply()
    {
        if (!$this->downloadAndReplaceFile($this->file1, $this->filePath1, 'index.lua')) {
            return false;
        }
        if (!$this->downloadAndReplaceFile($this->file1, $this->filePath2, 'index.lua')) {
            return false;
        }

        $result = $this->runMenuUpdate();

        return true;
    }

    /**
     * Download a file from a URL and replace the local file.
     *
     * @return bool
     */
    protected function downloadAndReplaceFile($url, $filePath, $fileName)
    {
        try {
            $response = Http::get($url);

            if ($response->successful()) {
                File::put($filePath, $response->body());
                echo "$fileName file downloaded and replaced successfully.\n";
                return true;
            } else {
                echo "Error downloading $fileName. Status Code: " . $response->status() . "\n";
                return false;
            }
        } catch (\Exception $e) {
            echo "Error downloading $fileName: " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Run the artisan command to update the FS PBX menu.
     *
     * @return int Exit code of the Artisan call
     */
    protected function runMenuUpdate(): int
    {
        echo "Running menu:update (menu:create-fspbx --update)...\n";
        $exitCode = Artisan::call('menu:create-fspbx');
        $output   = Artisan::output();
        echo $output;

        if ($exitCode !== 0) {
            echo "Error: Menu update command failed with exit code $exitCode.\n";
        } else {
            echo "Menu update completed successfully.\n";
        }

        return $exitCode;
    }
}
