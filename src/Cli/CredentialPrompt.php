<?php

declare(strict_types=1);

namespace Edict\Cli;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Edict\Auth\Totp;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Shared terminal I/O for the admin commands: the hidden password prompt and
 * the one-time display of a new TOTP secret.
 */
final class CredentialPrompt
{
    /**
     * Reads the new password twice with hidden input. Refuses to fall back to
     * visible input, and non-interactive runs can use EDICT_ADMIN_PASSWORD.
     */
    public static function newPassword(QuestionHelper $helper, InputInterface $input, OutputInterface $output): string
    {
        $fromEnv = getenv('EDICT_ADMIN_PASSWORD');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        if (!$input->isInteractive()) {
            throw new RuntimeException('Not interactive: set EDICT_ADMIN_PASSWORD to provide the password.');
        }

        $first = self::askHidden($helper, $input, $output, 'New password: ');
        $second = self::askHidden($helper, $input, $output, 'Repeat password: ');
        if (!hash_equals($first, $second)) {
            throw new RuntimeException('The passwords do not match.');
        }

        return $first;
    }

    public static function showTotp(OutputInterface $output, string $secret, string $username): void
    {
        $uri = Totp::provisioningUri($secret, $username);

        $output->writeln('');
        $output->writeln('Scan this with your authenticator app. It is shown only this once:');
        $output->writeln('');
        $output->writeln(self::qrBlocks($uri));
        $output->writeln('');
        $output->writeln('Manual entry key: ' . Totp::base32($secret));
    }

    private static function askHidden(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt): string
    {
        $question = new Question($prompt);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $answer = $helper->ask($input, $output, $question);

        return is_string($answer) ? $answer : '';
    }

    /**
     * Renders a QR code with half-block characters: two matrix rows per text
     * line, dark modules on a light background so that scanners accept it on
     * dark terminals too.
     *
     * @return list<string>
     */
    private static function qrBlocks(string $data): array
    {
        $matrix = Encoder::encode($data, ErrorCorrectionLevel::L(), 'UTF-8')->getMatrix();
        $size = $matrix->getWidth();
        $quiet = 2;

        $dark = static function (int $x, int $y) use ($matrix, $size): bool {
            return $x >= 0 && $y >= 0 && $x < $size && $y < $size && $matrix->get($x, $y) === 1;
        };

        $lines = [];
        for ($y = -$quiet; $y < $size + $quiet; $y += 2) {
            $line = "\033[47;30m";
            for ($x = -$quiet; $x < $size + $quiet; ++$x) {
                $line .= match ([$dark($x, $y), $dark($x, $y + 1)]) {
                    [true, true] => '█',
                    [true, false] => '▀',
                    [false, true] => '▄',
                    default => ' ',
                };
            }
            $lines[] = $line . "\033[0m";
        }

        return $lines;
    }
}
