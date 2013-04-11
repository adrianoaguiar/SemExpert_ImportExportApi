<?php

class SemExpert_ImportExportApi_Model_Import_Api
extends Mage_Api_Model_Resource_Abstract
{
    private $_mimeTypes= array(
        'text/csv' => 'csv'
    );

    const STATUS_INVALID = 0;
    const STATUS_PARTIAL = 1;
    const STATUS_VALID = 2;

    public function validate($file)
    {
        $entity = 'catalog_product';
        $behavior = 'append';

        $data = array(
            'entity'   => $entity, // catalog_product | customer
            'behavior' => $behavior // append | replace | delete
        );

        /* @var $import Mage_ImportExport_Model_Import */
        $import = Mage::getModel('importexport/import')->setData($data);

        $validationResult = $import->validateSource($this->_uploadSource($import, $file));

        $messages = array();
        $status = self::STATUS_INVALID;

        try {

            if (!$import->getProcessedRowsCount()) {
                $this->_fault('data_invalid', Mage::helper('importexport')->__('File does not contain data. Please upload another one'));
            } else {
                if (!$validationResult) {
                    if ($import->getProcessedRowsCount() == $import->getInvalidRowsCount()) {
                        $messages[] = Mage::helper('importexport')->__('File is totally invalid. Please fix errors and re-upload file');
                    } elseif ($import->getErrorsCount() >= $import->getErrorsLimit()) {
                        $messages[] = Mage::helper('importexport')->__(
                            'Errors limit (%d) reached. Please fix errors and re-upload file',
                            $import->getErrorsLimit()
                        );
                    } else {
                        if ($import->isImportAllowed()) {
                            $messages[] = Mage::helper('importexportapi')->__('Please fix errors and re-upload file or simply call "importexportImportStart" method to skip rows with errors');
                            $status = self::STATUS_PARTIAL;
                        } else {
                            $messages[] = Mage::helper('importexport')->__('File is partially valid, but import is not possible');
                        }
                    }

                    // errors info
                    foreach ($import->getErrors() as $errorCode => $rows) {
                        $messages[] = $errorCode . ' ' . Mage::helper('importexport')->__('in rows:') . ' ' . implode(', ', $rows);
                    }

                    if (!$status) {
                        $this->_fault('data_invalid', implode("\n", $messages));
                    }

                } else {

                    if ($import->isImportAllowed()) {
                        $status = self::STATUS_VALID;
                        $messages += $import->getNotices();
                        $messages[] = Mage::helper('importexport')->__(
                            'Checked rows: %d, checked entities: %d, invalid rows: %d, total errors: %d',
                            $import->getProcessedRowsCount(),
                            $import->getProcessedEntitiesCount(),
                            $import->getInvalidRowsCount(),
                            $import->getErrorsCount()
                        );
                    } else {
                        $this->_fault('data_invalid', Mage::helper('importexport')->__('File is valid, but import is not possible'));
                    }
                }
            }

        } catch (Mage_Api_Exception $e) {
            throw $e;
        } catch (Exception $e) {
            $this->_fault('unknown');
        }

        $response = new stdClass();
        $response->status = $status;
        $response->messages = $messages;
        $response->processed_rows_count = $import->getProcessedRowsCount();
        $response->invalid_rows_count = $import->getInvalidRowsCount();
        $response->processed_entities_count = $import->getProcessedEntitiesCount();
        $response->errors_count = $import->getErrorsCount();

        return $response;
    }

    public function start()
    {
        return array('Hello', 'world!');
    }

    private function _uploadSource($import, $file)
    {
        $entity = $import->getEntity();

        if (!$file || !$file->mime || !$file->content) { // Validate parameters
            /* TODO el mensaje no se esta transmitiendo correctamente */
            $this->_fault('data_invalid', Mage::helper('importexportapi')->__('The file is not specified.'));
        }

        if (!isset($this->_mimeTypes[$file->mime])) { // Validate mime type
            /* TODO el mensaje no se esta transmitiendo correctamente */
            $this->_fault('data_invalid', Mage::helper('importexportapi')->__('Invalid file type.'));
        }

        $fileContent = @base64_decode($file->content, true); // Decode data in strict base64 alphabet mode

        if (!$fileContent) {
            $this->_fault('data_invalid', Mage::helper('importexportapi')->__('The file contents is not valid base64 data.'));
        }

        unset($file->content); // Free memory

        $tmpDirectory = $import->getWorkingDir();
        $fileName = $entity . '.' . $this->_mimeTypes[$file->mime];
        $ioAdapter = new Varien_Io_File();

        try {
            /* TODO Validate encoding */

            // Create temporary directory for api
            $ioAdapter->checkAndCreateFolder($tmpDirectory);
            $ioAdapter->open(array('path'=>$tmpDirectory));

            // Write file
            $ioAdapter->write($fileName, $fileContent, 0666);
            unset($fileContent);
        } catch (Mage_Core_Exception $e) {
            $this->_fault('not_created', $e->getMessage());
        } catch (Exception $e) {
            $this->_fault('not_created', Mage::helper('importexport')->__('Cannot save file.'));
        }

        $sourceFile = $tmpDirectory . DS . $fileName;

        try {
            Mage_ImportExport_Model_Import_Adapter::findAdapterFor($sourceFile);
        } catch (Exception $e) {
            unlink($sourceFile);
            Mage::throwException($e->getMessage());
        }

        return $sourceFile;

    }
}