<?php

/*
 * Copyright (C) 2023 Deciso B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Trust\FieldTypes;

use OPNsense\Core\Config;
use OPNsense\Base\FieldTypes\ArrayField;
use OPNsense\Base\FieldTypes\ContainerField;
use OPNsense\Base\FieldTypes\TextField;

/**
 * Class CertificateContainerField
 * @package OPNsense\Trust\FieldTypes
 */
class CertificateContainerField extends ContainerField
{
    /**
     * @return array dn
     */
    public function dn()
    {
        $dn = [];
        foreach (
            [
            'country' => 'countryName',
            'state' => 'stateOrProvinceName',
            'city' => 'localityName',
            'organization' => 'organizationName',
            'organizationalunit' => 'organizationalUnitName',
            'email' => 'emailAddress',
            'commonname' => 'commonName',
            ] as $source => $target
        ) {
            if (!empty((string)$this->$source)) {
                $dn[$target] = (string)$this->$source;
            }
        }

        return $dn;
    }

    /**
     * @return array additional openssl properties (subjectAltNames)
     */
    public function extns()
    {
        $extns = [];
        $tmp = [];
        foreach (['DNS', 'IP', 'email', 'URI'] as $topic) {
            $fieldname = strtolower("altnames_" . $topic);
            if (!empty(trim((string)$this->$fieldname))) {
                foreach (explode("\n", (string)$this->$fieldname) as $line) {
                    if (!empty($line)) {
                        $tmp[] = $topic . ":" . $line;
                    }
                }
            }
        }
        if (!empty($tmp)) {
            $extns['subjectAltName'] = implode(",", $tmp);
        }
        return $extns;
    }
}

/**
 * Class CertificatesField
 * @package OPNsense\Trust\FieldTypes
 */
class CertificatesField extends ArrayField
{
    /**
     * @inheritDoc
     */
    public function newContainerField($ref, $tagname)
    {
        $container_node = new CertificateContainerField($ref, $tagname);
        $pmodel = $this->getParentModel();
        $container_node->setParentModel($pmodel);
        return $container_node;
    }

    protected function actionPostLoadingEvent()
    {
        foreach ($this->internalChildnodes as $node) {
            $node->csr_payload = !empty((string)$node->csr) ? (string)base64_decode($node->csr) : '';
            $node->crt_payload = !empty((string)$node->crt) ? (string)base64_decode($node->crt) : '';
            $payload = false;
            if (!empty((string)$node->crt)) {
                $payload = \OPNsense\Trust\Store::parseX509($node->crt_payload);
            } elseif (!empty((string)$node->csr)) {
                $payload = \OPNsense\Trust\Store::parseCSR($node->csr_payload);
            }
            if ($payload !== false) {
                foreach ($payload as $key => $value) {
                    if (isset($node->$key)) {
                        $node->$key = $value;
                    }
                }
            }
            $node->prv_payload = !empty((string)$node->prv) ? (string)base64_decode($node->prv) : '';
            if (!empty((string)$node->csr_payload)) {
                $node->action = 'import_csr';
            } elseif (!empty((string)$node->crt_payload) && !empty((string)$node->prv_payload)) {
                $node->action = 'reissue';
            } elseif (!empty((string)$node->crt_payload)) {
                $node->action = 'manual';
            }
            $tmp = Config::getInstance()->object()->xpath("//*[text() = '{$node->refid}']");
            if (is_array($tmp) && count($tmp) > 1) {
                $node->in_use = '1';
            }
        }
        return parent::actionPostLoadingEvent();
    }
}
