<?php
declare( strict_types = 1 );

namespace Itdatex\Mailguard\Customer;

/**
 * HTML-Templates fuer transaktionale Mails im MailGuard-Precision-Monochrome-
 * Stil. Email-Client-safe: Tables fuer Layout, Inline-CSS, Web-safe fonts mit
 * Fallback (Bebas Neue nicht ladbar in Mails -> condensed sans fallback), nur
 * grundlegende CSS-Properties die Gmail/Outlook/Apple-Mail durchlassen.
 */
final class MailTemplate {

	public const BG         = '#0d1117';
	public const SURFACE    = '#161b22';
	public const BORDER     = '#30363d';
	public const FG         = '#e6edf3';
	public const MUTED      = '#8b949e';
	public const SUBTLE     = '#6e7681';
	public const ACCENT     = '#f0f6fc';
	public const ACCENT_FG  = '#0d1117';
	public const ERR        = '#f85149';
	public const OK         = '#3fb950';

	/**
	 * @param string $preview_text Preheader-Text, erscheint neben dem Betreff
	 *                             in Mail-Clients. Ca. 90-110 Zeichen.
	 * @param string $kicker       Mono-Caps-Text ueber dem Haupt-Titel
	 * @param string $title        Haupt-Titel (Display-Font)
	 * @param string $intro        Optional: Intro-Absatz unter dem Titel
	 * @param array{label:string,url:string} $cta Primary-Button
	 * @param string[] $details    Optionale "Details"-Rows (als Mono-Code-Lines)
	 * @param string $footer_note  Optional: Fuss-Hinweis
	 */
	public static function render(
		string $preview_text,
		string $kicker,
		string $title,
		string $intro,
		array $cta,
		array $details = [],
		string $footer_note = ''
	) : string {
		$cta_html = '';
		if ( ! empty( $cta['label'] ) && ! empty( $cta['url'] ) ) {
			$cta_html = sprintf(
				'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0;">
					<tr><td style="border-radius:7px;background:%1$s;">
						<a href="%2$s" target="_blank" style="display:inline-block;padding:14px 32px;font-family:%7$s;font-size:14px;font-weight:600;letter-spacing:0.02em;color:%3$s;background:%1$s;border:1px solid %1$s;border-radius:7px;text-decoration:none;">%4$s</a>
					</td></tr>
				</table>
				<p style="margin:12px 0 0;font-family:%6$s;font-size:12px;color:%5$s;line-height:1.6;">
					Falls der Button nicht klickbar ist, kopiere die URL in deinen Browser:<br>
					<span style="color:%3$s;word-break:break-all;">%2$s</span>
				</p>',
				esc_attr( self::ACCENT ),
				esc_attr( $cta['url'] ),
				esc_attr( self::ACCENT_FG ),
				esc_html( $cta['label'] ),
				esc_attr( self::MUTED ),
				esc_attr( "'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace" ),
				esc_attr( self::body_font() )
			);
		}

		$details_html = '';
		if ( ! empty( $details ) ) {
			$rows = '';
			foreach ( $details as $d ) {
				$rows .= sprintf(
					'<tr><td style="padding:8px 0;border-top:1px solid %1$s;font-family:%2$s;font-size:12px;color:%3$s;letter-spacing:0.05em;">%4$s</td></tr>',
					esc_attr( self::BORDER ),
					esc_attr( "'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace" ),
					esc_attr( self::MUTED ),
					wp_kses_post( $d )
				);
			}
			$details_html = sprintf(
				'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%%" style="margin:24px 0 0;border-bottom:1px solid %1$s;">%2$s</table>',
				esc_attr( self::BORDER ),
				$rows
			);
		}

		$footer_note_html = '';
		if ( $footer_note !== '' ) {
			$footer_note_html = sprintf(
				'<p style="margin:24px 0 0;font-family:%1$s;font-size:12px;color:%2$s;line-height:1.6;">%3$s</p>',
				esc_attr( self::body_font() ),
				esc_attr( self::SUBTLE ),
				esc_html( $footer_note )
			);
		}

		return sprintf(
			'<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>%1$s</title>
</head>
<body style="margin:0;padding:0;background:%2$s;color:%3$s;font-family:%17$s;">
<span style="display:none !important;visibility:hidden;mso-hide:all;font-size:0;line-height:0;max-height:0;max-width:0;opacity:0;overflow:hidden;">%4$s</span>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%%" style="background:%2$s;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="520" style="max-width:520px;width:100%%;background:%5$s;border:1px solid %6$s;border-radius:12px;">
<tr><td style="padding:40px 36px;">
	<p style="margin:0 0 24px;font-family:%7$s;font-size:12px;letter-spacing:0.14em;color:%8$s;text-transform:uppercase;">
		<span style="color:%3$s;font-weight:700;">MAILGUARD</span> &middot; %9$s
	</p>
	<h1 style="margin:0 0 12px;font-family:%10$s;font-weight:400;font-size:40px;line-height:1.05;letter-spacing:0.02em;color:%3$s;">%11$s</h1>
	<p style="margin:0;font-family:%17$s;font-size:15px;line-height:1.6;color:%12$s;">%13$s</p>
	%14$s
	%15$s
	%16$s
</td></tr>
</table>
<p style="margin:20px 0 0;font-family:%7$s;font-size:11px;color:%8$s;letter-spacing:0.1em;text-transform:uppercase;text-align:center;">
	itdatex &middot; MAILGUARD &middot; wp.itdatex.support
</p>
</td></tr>
</table>
</body></html>',
			esc_html( $title ),                                 // 1 title
			esc_attr( self::BG ),                               // 2 bg
			esc_attr( self::FG ),                               // 3 fg
			esc_html( $preview_text ),                          // 4 preheader
			esc_attr( self::SURFACE ),                          // 5 surface
			esc_attr( self::BORDER ),                           // 6 border
			esc_attr( "'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace" ), // 7 mono
			esc_attr( self::MUTED ),                            // 8 muted
			esc_html( $kicker ),                                // 9 kicker
			esc_attr( self::display_font() ),                   // 10 display
			esc_html( $title ),                                 // 11 title display
			esc_attr( self::MUTED ),                            // 12 muted again
			wp_kses_post( $intro ),                             // 13 intro (allows <strong>)
			$cta_html,                                          // 14
			$details_html,                                      // 15
			$footer_note_html,                                  // 16
			esc_attr( self::body_font() )                       // 17 body font
		);
	}

	private static function display_font() : string {
		return "'Bebas Neue', 'Arial Narrow', 'Impact', sans-serif";
	}
	private static function body_font() : string {
		return "'IBM Plex Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
	}
}
