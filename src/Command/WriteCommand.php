<?php

declare(strict_types=1);

namespace Azura\MetadataManager\Command;

use Azura\MetadataManager\Metadata;
use getID3;
use getid3_writetags;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class WriteCommand extends Command
{
    protected function configure(): void
    {
        $this->setDefinition(
            [
                new InputArgument('path', InputArgument::REQUIRED, 'file path'),
                new InputArgument('json-input', InputArgument::REQUIRED, 'json input path'),
                new InputArgument('art-input', InputArgument::OPTIONAL, 'art input path')
            ]
        );
    }

    public function getDescription(): string
    {
        return 'Write metadata (and optionally an artwork file) to a media file.';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = $input->getArgument('path');
        $jsonInput = $input->getArgument('json-input');
        $artInput = $input->getArgument('art-input');

        $getID3 = new getID3();
        $getID3->setOption(['encoding' => 'UTF8']);

        $tagwriter = new getid3_writetags();
        $tagwriter->filename = $path;

        $pathExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $tagFormats = match ($pathExt) {
            'mp3', 'mp2', 'mp1', 'riff' => ['id3v1', 'id3v2.3'],
            'mpc' => ['ape'],
            'flac' => ['metaflac'],
            'real' => ['real'],
            'ogg' => ['vorbiscomment'],
            default => null
        };

        if (null === $tagFormats) {
            $io->error('Cannot write tag formats based on file type.');
            return 1;
        }

        $tagwriter->tagformats = $tagFormats;
        $tagwriter->overwrite_tags = true;
        $tagwriter->tag_encoding = 'UTF8';
        $tagwriter->remove_other_tags = true;

        if (!is_file($jsonInput)) {
            $io->error(sprintf('File not found: %s', $jsonInput));
            return 1;
        }

        $fileContents = file_get_contents($jsonInput);
        if (empty($fileContents)) {
            $io->error(sprintf('Source file %s is empty.', $jsonInput));
            return 1;
        }

        try {
            $json = (array)json_decode($fileContents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $io->error(sprintf('JSON parsing error: %s', $e->getMessage()));
            return 1;
        }

        $writeTags = Metadata::fromJson($json)->getTags();

        if ($artInput && is_file($artInput)) {
            $artContents = file_get_contents($artInput);
            if (false !== $artContents) {
                $writeTags['attached_picture'] = [
                    'encodingid' => 0, // ISO-8859-1; 3=UTF8 but only allowed in ID3v2.4
                    'description' => 'cover art',
                    'data' => $artContents,
                    'picturetypeid' => 0x03,
                    'mime' => 'image/jpeg',
                ];
            }
        }

        // All ID3 tags have to be written as ['key' => ['value']] (i.e. with "value" at position 0).
        $tagData = [];
        foreach ($writeTags as $tagKey => $tagValue) {
            $tagData[$tagKey] = [$tagValue];
        }

        $tagwriter->tag_data = $tagData;
        $tagwriter->WriteTags();

        if (!empty($tagwriter->errors) || !empty($tagwriter->warnings)) {
            $messages = array_merge($tagwriter->errors, $tagwriter->warnings);

            $io->error(
                sprintf(
                    'Cannot process media file %s: %s',
                    $path,
                    implode(', ', $messages)
                )
            );
            return 1;
        }

        return 0;
    }
}
