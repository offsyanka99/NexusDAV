<?php

namespace App\Services\Dav\Push;

use App\Services\Push\PushConfig;
use App\Services\Push\PushRegistration;
use App\Services\Push\PushTopics;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Sabre\DAV\Exception\BadRequest;

/**
 * Reads a push-register body (draft-bitfire-webdav-push-00). document()
 * tells a registration apart from any other POST; registration() checks
 * the Web Push subscription and returns what the client asked for.
 */
final class PushRegisterParser
{
    public const MAX_BODY = 65536;

    public const MAX_PUSH_RESOURCE = 2048;

    private const MAX_PROPERTIES = 50;

    /**
     * SubjectPublicKeyInfo header of an uncompressed P-256 point.
     */
    private const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * The push-register root element, or null when the body is not one.
     */
    public function document(string $body): ?DOMElement
    {
        if (! str_contains($body, 'push-register')) {
            return null;
        }

        if (strlen($body) > self::MAX_BODY) {
            throw new PushPrecondition(PushPrecondition::INVALID_SUBSCRIPTION, 'The push-register body is too large.');
        }

        // No DTD, so no entity can be declared or expanded.
        if (stripos($body, '<!DOCTYPE') !== false) {
            throw new BadRequest('A push-register body must not have a DOCTYPE.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($body, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;

        if (! $loaded || ! $root instanceof DOMElement) {
            throw new BadRequest('The push-register body is not well-formed XML.');
        }

        return $root->namespaceURI === PushPlugin::NS && $root->localName === 'push-register' ? $root : null;
    }

    public function registration(DOMElement $root): PushRegistration
    {
        $web = $this->child($this->child($root, PushPlugin::NS, 'subscription'), PushPlugin::NS, 'web-push-subscription');

        if ($web === null) {
            throw $this->invalid('A web-push-subscription is required.');
        }

        $resource = $this->pushResource($this->text($this->child($web, PushPlugin::NS, 'push-resource')));

        if (strtolower($this->text($this->child($web, PushPlugin::NS, 'content-encoding'))) !== 'aes128gcm') {
            throw $this->invalid('The content-encoding must be aes128gcm.');
        }

        $keyElement = $this->child($web, PushPlugin::NS, 'subscription-public-key');
        $type = $keyElement?->getAttribute('type') ?? '';

        if ($type !== '' && strtolower($type) !== 'p256dh') {
            throw $this->invalid('The subscription-public-key must be of type p256dh.');
        }

        $publicKey = $this->text($keyElement);
        $point = $this->base64Url($publicKey);

        if ($point === null || strlen($point) !== 65 || $point[0] !== "\x04" || ! $this->onCurve($point)) {
            throw $this->invalid('The subscription-public-key must be an uncompressed P-256 public key.');
        }

        $authSecret = $this->text($this->child($web, PushPlugin::NS, 'auth-secret'));
        $secret = $this->base64Url($authSecret);

        if ($secret === null || strlen($secret) !== 16) {
            throw $this->invalid('The auth-secret must be 16 bytes.');
        }

        $trigger = $this->child($root, PushPlugin::NS, 'trigger');
        $content = $this->child($trigger, PushPlugin::NS, 'content-update');
        $property = $this->child($trigger, PushPlugin::NS, 'property-update');

        return new PushRegistration(
            pushResource: $resource,
            publicKey: $publicKey,
            authSecret: $authSecret,
            contentUpdate: $content !== null,
            contentDepth: $this->depth($content),
            propertyUpdate: $property !== null,
            propertyDepth: $this->depth($property),
            properties: $this->properties($property),
            expires: $this->expires($this->text($this->child($root, PushPlugin::NS, 'expires'))),
        );
    }

    /**
     * An absolute https URL without user info. Plain http only when private
     * networks are allowed, for a push service on the LAN.
     */
    private function pushResource(string $url): string
    {
        $parts = parse_url($url);

        if ($url === '' || strlen($url) > self::MAX_PUSH_RESOURCE || ! is_array($parts)) {
            throw $this->invalid('The push-resource must be an absolute https URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $allowed = PushConfig::allowPrivate() ? ['https', 'http'] : ['https'];

        if (! in_array($scheme, $allowed, true) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw $this->invalid('The push-resource must be an absolute https URL.');
        }

        return $url;
    }

    private function depth(?DOMElement $trigger): ?string
    {
        $depth = strtolower($this->text($this->child($trigger, 'DAV:', 'depth')));

        return in_array($depth, PushTopics::DEPTHS, true) ? $depth : null;
    }

    /**
     * @return list<string>|null
     */
    private function properties(?DOMElement $trigger): ?array
    {
        $prop = $this->child($trigger, 'DAV:', 'prop');

        if ($prop === null) {
            return null;
        }

        $names = [];

        foreach ($prop->childNodes as $node) {
            if ($node instanceof DOMElement && count($names) < self::MAX_PROPERTIES) {
                $names[] = '{'.($node->namespaceURI ?? '').'}'.$node->localName;
            }
        }

        return $names === [] ? null : $names;
    }

    /**
     * IMF-fixdate. Anything else is ignored and the default expiry applies.
     */
    private function expires(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }

    private function child(?DOMElement $parent, string $namespace, string $name): ?DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === $namespace && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    private function text(?DOMElement $element): string
    {
        return $element === null ? '' : trim($element->textContent);
    }

    private function base64Url(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_\-+\/]+={0,2}$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * OpenSSL refuses a point that is not on the curve.
     */
    private function onCurve(string $point): bool
    {
        $der = (string) hex2bin(self::P256_SPKI).$point;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);

        while (openssl_error_string() !== false) {
            // Drain the queue so a later OpenSSL call does not report these.
        }

        return $key !== false;
    }

    private function invalid(string $message): PushPrecondition
    {
        return new PushPrecondition(PushPrecondition::INVALID_SUBSCRIPTION, $message);
    }
}
