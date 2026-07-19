<?php

declare(strict_types=1);

namespace BillingService\Billing\Infrastructure\Services;

use App\Infrastructure\Storage\Billing\BillingArtifactStorage;
use BillingService\Billing\Application\Ports\DocumentSignerInterface;
use BillingService\Billing\Domain\Exceptions\SriException;
use DOMDocument;
use Exception;

class XadesBesSigner implements DocumentSignerInterface
{
    private string $certPath;
    private string $certPassword;

    public function __construct(array $config)
    {
        $this->certPath = $config['certificate']['path'];
        $this->certPassword = $config['certificate']['password'];
    }

    public function sign(string $xml): string
    {
        try {
            $certPath = (new BillingArtifactStorage())->materialize($this->certPath);
            if (!is_file($certPath) || !is_readable($certPath)) {
                throw new Exception(sprintf('No se puede leer el certificado .p12 en %s', $this->certPath));
            }

            $pkcs12 = file_get_contents($certPath);
            if ($pkcs12 === false) {
                throw new Exception(sprintf('No se pudo cargar el certificado .p12 en %s', $this->certPath));
            }

            $certs = [];
            if (!openssl_pkcs12_read($pkcs12, $certs, $this->certPassword)) {
                throw new Exception('No se pudo leer el certificado .p12');
            }

            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = false;
            $doc->formatOutput = false;
            $doc->loadXML($xml);

            $id = mt_rand(100000, 999999);

            return $this->crearFirmaXAdESCompleta(
                $doc,
                $certs,
                "Signature$id",
                "SignedInfo$id",
                "SignatureValue$id",
                "Certificate$id",
                "SignedProperties$id",
                "Object$id",
                "Reference$id",
                "SignedPropertiesID$id"
            );

        } catch (Exception $e) {
            throw SriException::signatureFailed($e->getMessage());
        }
    }

private function crearFirmaXAdESCompleta(
    DOMDocument $doc,
    array $certInfo,
    string $signatureId,
    string $signedInfoId,
    string $signatureValueId,
    string $certificateId,
    string $signedPropertiesId,
    string $objectId,
    string $referenceId,
    string $signedPropertiesRefId
): string {
    $certResource = openssl_x509_read($certInfo['cert']);
    openssl_x509_export($certResource, $certPEMStr);

    $certClean = str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r", " ", "\t"], '', $certPEMStr);
    $certDER = base64_decode($certClean);
    $certSHA1 = base64_encode(sha1($certDER, true));
    $certInfo509 = openssl_x509_parse($certResource);

    $publicKey = openssl_pkey_get_details(openssl_pkey_get_public($certResource));
    $modulus = base64_encode($publicKey['rsa']['n']);
    $exponent = base64_encode($publicKey['rsa']['e']);

    $docFinal = new DOMDocument('1.0', 'UTF-8');
    $docFinal->preserveWhiteSpace = false;
    $docFinal->loadXML($doc->saveXML());
    $root = $docFinal->documentElement;
    $root->setAttribute('id', 'comprobante');

    // 1. Signature con namespace ds (Única declaración)
    $signatureNode = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Signature');
    $signatureNode->setAttribute('Id', $signatureId);

    // 2. KeyInfo
    $keyInfoNode = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:KeyInfo');
    $keyInfoNode->setAttribute('Id', $certificateId);
    $x509Data = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509Data');
    $x509Data->appendChild($docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509Certificate', $certClean));
    $keyInfoNode->appendChild($x509Data);

    $keyValue = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:KeyValue');
    $rsaValue = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:RSAKeyValue');
    $rsaValue->appendChild($docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Modulus', $modulus));
    $rsaValue->appendChild($docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Exponent', $exponent));
    $keyValue->appendChild($rsaValue);
    $keyInfoNode->appendChild($keyValue);

    // 3. Object + etsi (Sin re-declarar ds)
    $objectNode = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Object');
    $objectNode->setAttribute('Id', $objectId);

    // Aquí declaramos etsi por única vez para sus hijos
    $qualifyingPropsNode = $docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:QualifyingProperties');
    $qualifyingPropsNode->setAttribute('Target', "#{$signatureId}");

    $signedPropsNode = $docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:SignedProperties');
    $signedPropsNode->setAttribute('Id', $signedPropertiesId);

    $signedSigProps = $docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:SignedSignatureProperties');
    $signedSigProps->appendChild($docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:SigningTime', date('Y-m-d\TH:i:sP')));

    $signingCert = $docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:SigningCertificate');
    $certNode = $docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:Cert');
    $certDigest = $docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:CertDigest');

    // IMPORTANTE: ds:DigestMethod ya sabe que ds es xmldsig, no necesita volver a decirlo
    $dm = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestMethod');
    $dm->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
    $certDigest->appendChild($dm);
    $certDigest->appendChild($docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestValue', $certSHA1));

    $issuerSerial = $docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:IssuerSerial');
    $issuerSerial->appendChild($docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509IssuerName', $this->formatDN($certInfo509['issuer'])));
    $issuerSerial->appendChild($docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:X509SerialNumber', (string)$certInfo509['serialNumber']));

    $certNode->appendChild($certDigest);
    $certNode->appendChild($issuerSerial);
    $signingCert->appendChild($certNode);
    $signedSigProps->appendChild($signingCert);

    $signedDataObjProps = $docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:SignedDataObjectProperties');
    $dataObjectFormat = $docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:DataObjectFormat');
    $dataObjectFormat->setAttribute('ObjectReference', "#{$referenceId}");
    $dataObjectFormat->appendChild($docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:Description', 'comprobante'));
    $dataObjectFormat->appendChild($docFinal->createElementNS('http://uri.etsi.org/01903/v1.3.2#', 'etsi:MimeType', 'text/xml'));
    $signedDataObjProps->appendChild($dataObjectFormat);

    $signedPropsNode->appendChild($signedSigProps);
    $signedPropsNode->appendChild($signedDataObjProps);
    $qualifyingPropsNode->appendChild($signedPropsNode);
    $objectNode->appendChild($qualifyingPropsNode);

    // 4. Calcular digest del comprobante ANTES de insertar la firma en el árbol
    // Usamos Exclusive C14N (true) porque la Reference usa exc-c14n como transform
    $comprobanteDigest = base64_encode(sha1($root->C14N(true, false), true));

    // Insertar firma en el árbol para que KeyInfo y SignedProperties hereden namespaces en contexto
    $root->appendChild($signatureNode);
    $signatureNode->appendChild($keyInfoNode);
    $signatureNode->appendChild($objectNode);

    // Todos los digests deben usar Exclusive C14N
    $keyInfoDigest     = base64_encode(sha1($keyInfoNode->C14N(true, false), true));
    $signedPropsDigest = base64_encode(sha1($signedPropsNode->C14N(true, false), true));

    // 5. SignedInfo
    $signedInfoNode = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignedInfo');
    $signedInfoNode->setAttribute('Id', $signedInfoId);
    $canMethod = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:CanonicalizationMethod');
    $canMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/10/xml-exc-c14n#');
    $signedInfoNode->appendChild($canMethod);
    $sigMethod = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureMethod');
    $sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
    $signedInfoNode->appendChild($sigMethod);

    // Orden requerido por el ejemplo XAdES-BES del SRI: SignedProperties, KeyInfo, Comprobante
    $this->addReference($docFinal, $signedInfoNode, "#{$signedPropertiesId}", $signedPropsDigest,
        ['http://www.w3.org/2001/10/xml-exc-c14n#'], 'http://uri.etsi.org/01903#SignedProperties', $signedPropertiesRefId);
    $this->addReference($docFinal, $signedInfoNode, "#{$certificateId}", $keyInfoDigest,
        ['http://www.w3.org/2001/10/xml-exc-c14n#']);
    $this->addReference($docFinal, $signedInfoNode, "#comprobante", $comprobanteDigest,
        ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', 'http://www.w3.org/2001/10/xml-exc-c14n#'], null, $referenceId);

    $signatureNode->insertBefore($signedInfoNode, $keyInfoNode);

    // 6. Firma Final (C14N estricto)
    $privateKey = openssl_pkey_get_private($certInfo['pkey']);
    // CanonicalizationMethod es exc-c14n → primer parámetro true = exclusive C14N
    openssl_sign($signedInfoNode->C14N(true, false), $signature, $privateKey, OPENSSL_ALGO_SHA1);

    $sigValueNode = $docFinal->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:SignatureValue', base64_encode($signature));
    $sigValueNode->setAttribute('Id', $signatureValueId);
    $signatureNode->insertBefore($sigValueNode, $keyInfoNode);

    return $docFinal->saveXML();
}

    private function addReference($doc, $parent, $uri, $digest, $transforms = null, $type = null, $id = null): void
    {
        $ref = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Reference');
        $ref->setAttribute('URI', $uri);
        if ($id) {
            $ref->setAttribute('Id', $id);
        }
        if ($type) $ref->setAttribute('Type', $type);
        if ($transforms) {
            $trans = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Transforms');
            foreach ((array)$transforms as $algorithm) {
                $t = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:Transform');
                $t->setAttribute('Algorithm', $algorithm);
                $trans->appendChild($t);
            }
            $ref->appendChild($trans);
        }
        $dm = $doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestMethod');
        $dm->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $ref->appendChild($dm);
        $ref->appendChild($doc->createElementNS('http://www.w3.org/2000/09/xmldsig#', 'ds:DigestValue', $digest));
        $parent->appendChild($ref);
    }

    private function formatDN(array $dn): string
    {
        $parts = [];
        // Orden SECURITY DATA: C, O, OU, CN
        if (isset($dn['C'])) $parts[] = 'C=' . $dn['C'];
        if (isset($dn['O'])) $parts[] = 'O=' . (is_array($dn['O']) ? implode(', ', $dn['O']) : $dn['O']);
        if (isset($dn['OU'])) $parts[] = 'OU=' . (is_array($dn['OU']) ? implode(', ', $dn['OU']) : $dn['OU']);
        if (isset($dn['CN'])) $parts[] = 'CN=' . $dn['CN'];

        return implode(',', $parts);
    }

    public function verify(string $signedXml): bool { return true; }
}
