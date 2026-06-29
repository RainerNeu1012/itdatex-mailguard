<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Imap\Autoconfig;

/**
 * Orchestriert die 5 Autodiscover-Quellen mit Confidence + Source-Tagging.
 *
 * Reihenfolge:
 *   1. ProviderRegistry        (offline, high)    — Top-Provider hardcoded
 *   2. MozillaAutoconfig       (HTTP, high)       — Thunderbird ISPDB
 *   3. MicrosoftAutodiscover   (HTTP+XML, medium) — Custom-Domains auf MS 365
 *   4. SrvDiscovery            (DNS, medium)      — RFC 6186 SRV-Records
 *   5. LlmDiscovery            (HTTPS, low)       — GLM-5.2 in der Cloud
 *
 * Erstes nicht-leeres Match gewinnt. Wenn alles fehlschlägt → null.
 *
 * Sondertyp: ProviderRegistry kann ein `no_imap`-Flag setzen
 * (ProtonMail, Tuta, Apple-Relay) — das returnen wir 1:1 weiter,
 * damit das Frontend einen klaren "kein IMAP verfügbar"-Hinweis zeigt.
 *
 * Privacy-Hinweis zu Stufe 5: NUR die Domain wird an die LLM-Cloud
 * uebermittelt, keine Mailadresse und keine Mail-Inhalte. Anders als
 * der Mail-Scan (der Subject + Body schickt) ist Discovery DSGVO-arm.
 */
final class Resolver {

	/**
	 * @return array{host?:string,port?:int,encryption?:string,oauth_provider?:string,note?:string,no_imap?:bool,domain?:string,source?:string,confidence?:string}|null
	 */
	public static function for_email( string $email ) : ?array {
		$pos = strrpos( $email, '@' );
		if ( $pos === false ) { return null; }
		$domain = strtolower( substr( $email, $pos + 1 ) );

		// 1. Static
		$hit = ProviderRegistry::lookup_by_domain( $domain );
		if ( $hit ) { return $hit; }

		// 2. Mozilla
		$hit = MozillaAutoconfig::lookup( $domain );
		if ( $hit ) { return $hit; }

		// 3. Microsoft Autodiscover (mit echter Email — manche Endpoints verlangen das)
		$hit = MicrosoftAutodiscover::lookup( $email );
		if ( $hit ) { return $hit; }

		// 4. DNS SRV
		$hit = SrvDiscovery::lookup( $domain );
		if ( $hit ) { return $hit; }

		// 5. LLM-Discovery (nur Domain, kein Mail-Inhalt)
		$hit = LlmDiscovery::lookup( $domain );
		if ( $hit ) { return $hit; }

		return null;
	}
}
