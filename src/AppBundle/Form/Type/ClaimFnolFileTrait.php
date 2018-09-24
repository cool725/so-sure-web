<?php

namespace AppBundle\Form\Type;

use AppBundle\Classes\NoOp;
use AppBundle\Exception\ValidationException;
use AppBundle\Service\ClaimsService;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait ClaimFnolFileTrait
{
    public static $uploadFileExtensions = [
        ".pdf",
        ".doc",
        ".docx",
        ".jpg",
        ".jpeg",
        ".png",
    ];

    public static $uploadMimeTypes = [
        "application/pdf",
        "application/x-pdf",
        "application/msword",
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "image/jpeg",
        "image/png",
    ];

    public function handleFile(
        UploadedFile $file,
        ClaimsService $claimsService,
        FormInterface $form,
        $userId,
        $filename,
        $field
    ) {
        try {
            $errorMessage = "Please upload a supported file type (pdf, doc, docx, png, or jpg)";
            if (mb_strtolower($file->getMimeType()) == 'application/octet-stream' &&
                !in_array(mb_strtolower($file->getClientOriginalExtension()), self::$uploadFileExtensions)) {
                    throw new ValidationException($errorMessage);
            } elseif (!in_array(mb_strtolower($file->getMimeType()), self::$uploadMimeTypes)) {
                throw new ValidationException($errorMessage);
            }
            $extension = $file->guessExtension();
            $s3key = $claimsService->uploadS3(
                $file,
                $filename,
                $userId,
                $extension
            );

            return $s3key;
        } catch (FileNotFoundException $e) {
            // skip missing file
            NoOp::ignore([]);
        } catch (ValidationException $e) {
            $form->get($field)->addError(new FormError($e->getMessage()));
        }

        return null;
    }
}
