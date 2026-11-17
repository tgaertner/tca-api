<?php

declare(strict_types=1);

namespace MaikSchneider\TcaApi\Validation;

use MaikSchneider\TcaApi\Configuration\UploadDefinition;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Resource\MimeTypeDetector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Validation\Validator\FileExtensionValidator;
use TYPO3\CMS\Extbase\Validation\Validator\FileSizeValidator;
use TYPO3\CMS\Extbase\Validation\Validator\MimeTypeValidator;

/**
 * Validates a TYPO3 UploadedFile against an UploadDefinition constraint set
 * and the TCA type=file column definition.
 *
 * Allowed extensions and their derived MIME types are read from TCA at validation
 * time — they are not duplicated in the API column config.
 *
 * The caller (FileUploadTrait) is responsible for ensuring $file is backed by a
 * real file path (not a stream) before calling validate(). TYPO3's built-in
 * validators require getTemporaryFileName() to return a non-null path.
 *
 * Returns violation arrays in the same format as FieldValidator so callers
 * can merge all violations before sending a single 422 response. Each message
 * starts with the client file name, so a client can tell which of several files
 * of a column was rejected; the caller passes an indexed propertyPath
 * (`downloads.1`) for the same reason.
 */
final class UploadValidator
{
    /**
     * Extensions that map to 'common-image-types' in TCA type=file columns.
     * Resolved at runtime from GFX.imagefile_ext, falling back to this constant.
     */
    private const FALLBACK_IMAGE_EXTENSIONS = 'gif,jpg,jpeg,tif,tiff,bmp,pcx,tga,png,pdf,ai,svg,webp,avif';

    /**
     * PHP upload errors that mean the file is too large: the limit of php.ini
     * (`upload_max_filesize`) or of the form (`MAX_FILE_SIZE`) was hit.
     */
    private const SIZE_UPLOAD_ERRORS = [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE];

    /**
     * @param string|null $propertyPath Path of the violations; defaults to $column
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    public function validate(
        UploadedFile $file,
        UploadDefinition $upload,
        string $table,
        string $column,
        ?string $propertyPath = null,
    ): array {
        $path   = $propertyPath ?? $column;
        $prefix = $this->filePrefix($file);

        // Upload transport error takes priority — nothing else is reliable.
        if ($file->getError() !== \UPLOAD_ERR_OK) {
            if (\in_array($file->getError(), self::SIZE_UPLOAD_ERRORS, true)) {
                return [$this->buildViolation(
                    $path,
                    $prefix . 'The file exceeds the maximum upload size of the server.',
                    'UPLOAD_MAX_SIZE',
                )];
            }

            return [$this->buildViolation(
                $path,
                $prefix . sprintf('The file upload failed (error code %d).', $file->getError()),
                'UPLOAD_ERROR',
            )];
        }

        return $this->runValidators($file, $upload, $table, $column, $path, $prefix);
    }

    /**
     * @return list<array{propertyPath: string, message: string, code: string}>
     */
    private function runValidators(
        UploadedFile $file,
        UploadDefinition $upload,
        string $table,
        string $column,
        string $path,
        string $prefix,
    ): array {
        $violations = [];
        $extensions = $this->resolveTcaExtensions($table, $column);

        // ── MIME type ────────────────────────────────────────────────────
        // Derive allowed MIME types from the TCA extensions via MimeTypeDetector.
        // MimeTypeValidator reads actual file content via finfo — more reliable
        // than extension-based checks alone.
        if ($extensions !== []) {
            $allowedMimeTypes = $this->extensionsToMimeTypes($extensions);
            if ($allowedMimeTypes !== []) {
                $validator = GeneralUtility::makeInstance(MimeTypeValidator::class);
                $validator->setOptions([
                    'allowedMimeTypes'         => $allowedMimeTypes,
                    'ignoreFileExtensionCheck' => true,
                    'notAllowedMessage'        => 'The file type is not allowed.',
                ]);
                foreach ($validator->validate($file)->getErrors() as $error) {
                    $violations[] = $this->buildViolation($path, $prefix . $error->getMessage(), 'UPLOAD_MIME_TYPE');
                }
            }
        }

        // ── File size ────────────────────────────────────────────────────
        if ($upload->maxSize !== null) {
            $validator = GeneralUtility::makeInstance(FileSizeValidator::class);
            $validator->setOptions([
                'maximum'       => $upload->maxSize . 'B',
                'exceedMessage' => sprintf(
                    'The file exceeds the maximum allowed size of %s.',
                    $this->formatSize($upload->maxSize),
                ),
            ]);
            foreach ($validator->validate($file)->getErrors() as $error) {
                $violations[] = $this->buildViolation($path, $prefix . $error->getMessage(), 'UPLOAD_MAX_SIZE');
            }
        }

        // ── File extension ───────────────────────────────────────────────
        if ($extensions !== []) {
            $validator = GeneralUtility::makeInstance(FileExtensionValidator::class);
            $validator->setOptions([
                'allowedFileExtensions' => $extensions,
                'notAllowedMessage'     => 'The file extension is not allowed.',
            ]);
            foreach ($validator->validate($file)->getErrors() as $error) {
                $violations[] = $this->buildViolation($path, $prefix . $error->getMessage(), 'UPLOAD_EXTENSION');
            }
        }

        return $violations;
    }

    /**
     * Read the list of allowed file extensions from the TCA type=file column.
     *
     * Handles the 'common-image-types' placeholder by resolving it via the
     * GFX.imagefile_ext site configuration (defaults to a safe fallback).
     *
     * @return string[] lowercase extension list; empty = unrestricted
     */
    private function resolveTcaExtensions(string $table, string $column): array
    {
        $tcaAllowed = $GLOBALS['TCA'][$table]['columns'][$column]['config']['allowed'] ?? '';

        if (!\is_string($tcaAllowed) || $tcaAllowed === '') {
            return [];
        }

        if (trim($tcaAllowed) === 'common-image-types') {
            $tcaAllowed = $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']
                ?? self::FALLBACK_IMAGE_EXTENSIONS;
        }

        return array_filter(
            array_map(
                strtolower(...),
                GeneralUtility::trimExplode(',', $tcaAllowed, true),
            ),
        );
    }

    /**
     * Convert file extensions to a flat, unique list of MIME types.
     *
     * @param  string[] $extensions
     * @return string[]
     */
    private function extensionsToMimeTypes(array $extensions): array
    {
        $detector   = new MimeTypeDetector();
        $mimeTypes  = [];

        foreach ($extensions as $ext) {
            foreach ($detector->getMimeTypesForFileExtension($ext) as $mime) {
                $mimeTypes[$mime] = true;
            }
        }

        return array_keys($mimeTypes);
    }

    /** "5 MB", "1.5 GB", "800 KB" — the limit as configured, without trailing zeros. */
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size  = (float)$bytes;
        $unit  = 0;
        while ($size >= 1024 && $unit < \count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return rtrim(rtrim(number_format($size, 1, '.', ''), '0'), '.') . ' ' . $units[$unit];
    }

    /** "'flyer.pdf': " — names the file in front of each message, empty without a client file name. */
    private function filePrefix(UploadedFile $file): string
    {
        $name = $file->getClientFilename();

        return $name !== null && $name !== '' ? sprintf("'%s': ", $name) : '';
    }

    /** @return array{propertyPath: string, message: string, code: string} */
    private function buildViolation(string $path, string $message, string $code): array
    {
        return ['propertyPath' => $path, 'message' => $message, 'code' => $code];
    }
}
