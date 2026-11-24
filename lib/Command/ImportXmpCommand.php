<?php

declare(strict_types=1);

namespace OCA\StarRate\Command;

use OCA\StarRate\Service\ExifService;
use OCA\StarRate\Service\TagService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ starrate:import-xmp <nc-path> [--user=<uid>] [--dry-run] [--overwrite] [--recursive]
 *
 * Liest xmp:Rating, xmp:Label und xmpDM:pick aus JPEG-Dateien und schreibt sie
 * in die StarRate Collaborative Tags-Datenbank.
 *
 * Typischer Anwendungsfall: einmaliger Import einer bestehenden
 * Lightroom/digiKam-Bibliothek in StarRate.
 */
class ImportXmpCommand extends Command
{
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly ExifService $exifService,
        private readonly TagService  $tagService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('starrate:import-xmp')
            ->setDescription('Import XMP ratings from JPEG files into StarRate')
            ->addArgument(
                'nc-path',
                InputArgument::REQUIRED,
                'NC folder path relative to user home (e.g. /Bilder/2024)'
            )
            ->addOption(
                'user', 'u',
                InputOption::VALUE_REQUIRED,
                'Nextcloud user ID'
            )
            ->addOption(
                'dry-run', null,
                InputOption::VALUE_NONE,
                'Show what would be imported without making changes'
            )
            ->addOption(
                'overwrite', null,
                InputOption::VALUE_NONE,
                'Overwrite existing StarRate ratings (default: skip already-rated files)'
            )
            ->addOption(
                'recursive', 'r',
                InputOption::VALUE_NONE,
                'Process all subfolders recursively'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ncPath    = $input->getArgument('nc-path');
        $userId    = $input->getOption('user');
        $dryRun    = (bool) $input->getOption('dry-run');
        $overwrite = (bool) $input->getOption('overwrite');
        $recursive = (bool) $input->getOption('recursive');

        if (!$userId) {
            $output->writeln('<error>--user is required</error>');
            return Command::FAILURE;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $node       = $ncPath === '/' ? $userFolder : $userFolder->get(ltrim($ncPath, '/'));
        } catch (NotFoundException) {
            $output->writeln("<error>Folder not found: {$ncPath}</error>");
            return Command::FAILURE;
        }

        if (!($node instanceof Folder)) {
            $output->writeln("<error>Path is not a folder: {$ncPath}</error>");
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>[dry-run] No changes will be written.</comment>');
        }

        $counters = ['imported' => 0, 'skipped' => 0, 'noXmp' => 0, 'errors' => 0, 'nonJpeg' => 0];

        $basePath = $userFolder->getPath();
        $this->processFolder($node, $basePath, $output, $dryRun, $overwrite, $recursive, $counters);

        $output->writeln(sprintf(
            '%s<comment>Done:</comment> %d imported, %d skipped (already rated), %d without XMP, %d non-JPEG, %d errors.',
            $dryRun ? '[dry-run] ' : '',
            $counters['imported'],
            $counters['skipped'],
            $counters['noXmp'],
            $counters['nonJpeg'],
            $counters['errors']
        ));

        return Command::SUCCESS;
    }

    /**
     * Verarbeitet einen Ordner und optional alle Unterordner rekursiv.
     *
     * @param array<string, int> $counters Referenz auf Zähler-Array
     */
    private function processFolder(
        Folder          $folder,
        string          $basePath,
        OutputInterface $output,
        bool            $dryRun,
        bool            $overwrite,
        bool            $recursive,
        array           &$counters,
    ): void {
        $items     = $folder->getDirectoryListing();
        $files     = array_filter($items, fn($n) => $n instanceof File);
        $subDirs   = $recursive ? array_filter($items, fn($n) => $n instanceof Folder) : [];

        $total       = count($files);
        $processed   = 0;
        $displayPath = '/' . ltrim(substr($folder->getPath(), strlen($basePath)), '/');

        $output->writeln(sprintf('Scanning %d files in %s...', $total, $displayPath));

        foreach ($files as $file) {
            $processed++;
            if ($processed % 100 === 0) {
                $output->writeln(sprintf('  [%d/%d] ...', $processed, $total));
            }

            $mime = $file->getMimeType();
            if (!in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
                $counters['nonJpeg']++;
                continue;
            }

            try {
                $xmp = $this->exifService->readMetadata($file);

                if ($xmp['rating'] === 0 && $xmp['label'] === null && $xmp['pick'] === 'none') {
                    $counters['noXmp']++;
                    continue;
                }

                $fileId = (string) $file->getId();

                if (!$overwrite) {
                    $existing = $this->tagService->getMetadata($fileId);
                    if ($existing['rating'] > 0 || $existing['color'] !== null || $existing['pick'] !== 'none') {
                        $counters['skipped']++;
                        if ($output->isVerbose()) {
                            $output->writeln(sprintf(
                                '  skip  %s (already rated: %d★ %s pick=%s)',
                                $file->getName(),
                                $existing['rating'],
                                $existing['color'] ?? '—',
                                $existing['pick']
                            ));
                        }
                        continue;
                    }
                }

                $label = $xmp['label'] ?? null;
                $pick  = $xmp['pick'];

                if ($dryRun) {
                    if ($output->isVerbose()) {
                        $output->writeln(sprintf(
                            '  <info>import</info> %s  rating=%d  label=%s  pick=%s',
                            $file->getName(),
                            $xmp['rating'],
                            $label ?? '—',
                            $pick
                        ));
                    }
                    $counters['imported']++;
                    continue;
                }

                $this->tagService->setMetadata($fileId, [
                    'rating' => $xmp['rating'],
                    'color'  => $label,
                    'pick'   => $pick,
                ]);

                if ($output->isVerbose()) {
                    $output->writeln(sprintf(
                        '  <info>import</info> %s  rating=%d  label=%s  pick=%s',
                        $file->getName(),
                        $xmp['rating'],
                        $label ?? '—',
                        $pick
                    ));
                }
                $counters['imported']++;

            } catch (\Exception $e) {
                $counters['errors']++;
                $output->writeln(sprintf(
                    '  <error>error</error>  %s: %s',
                    $file->getName(),
                    $e->getMessage()
                ));
            }
        }

        // Unterordner rekursiv verarbeiten
        foreach ($subDirs as $subDir) {
            $this->processFolder($subDir, $basePath, $output, $dryRun, $overwrite, $recursive, $counters);
        }
    }
}
