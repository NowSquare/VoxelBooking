<?php

declare(strict_types=1);

namespace App\Controllers\Booking;

use App\Engine\BrandColorHelper;
use App\Engine\Database;
use App\Engine\DemoMode;
use App\Engine\Locale;
use App\Engine\Request;
use App\Engine\Response;

/**
 * Embed widget controller (PRD §IX).
 *
 * Two public endpoints, no auth:
 * - GET /api/{slug}/embed-config  → tenant config JSON for the embed widget
 * - GET /embed/{slug}.js          → self-contained widget script (vanilla JS)
 *
 * Security: embed-config is public and cacheable (60s).
 * The script itself is fully static per tenant — browser-cached for 1 hour.
 */
final class EmbedController
{
    /**
     * GET /api/{slug}/embed-config
     *
     * Returns the public tenant config needed by the embed widget:
     * brand color, button settings, business name, booking pattern.
     */
    public function config(Request $request): Response
    {
        $slug = $request->getAttribute('slug');

        $tenant = Database::query(
            'SELECT `slug`, `name`, `brand_color`, `logo_path`,
                    `booking_pattern`, `embed_button_position`, `embed_button_label`,
                    `allowed_embed_domains`, `requires_consent`, `consent_text`,
                    `privacy_policy_url`, `custom_fields`, `locale`, `locale_override`
             FROM `tenants` WHERE `slug` = ? AND `status` = ? LIMIT 1',
            [$slug, 'active']
        );

        if (empty($tenant)) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $t = $tenant[0];

        // Same locale chain as the booking page opened by the widget, so the
        // button and overlay texts match the language of the booking flow.
        Locale::resolveForBooking($t, $request->header('Accept-Language'));

        // Parse allowed domains to array for the response
        $allowedDomains = [];
        if (!empty($t['allowed_embed_domains'])) {
            $allowedDomains = array_map('trim', explode(',', $t['allowed_embed_domains']));
        }

        // Auto-derive brand text color via WCAG luminance
        $brandTokens = BrandColorHelper::derive($t['brand_color'] ?? '#2563EB');

        $config = [
            'slug'              => $t['slug'],
            'name'              => $t['name'],
            'brand_color'       => $t['brand_color'],
            'brand_color_text'  => $brandTokens['brand_text'],
            'logo_url'          => $t['logo_path'] ? '/' . ltrim($t['logo_path'], '/') : null,
            'booking_pattern'   => $t['booking_pattern'],
            'button_position'   => $t['embed_button_position'] ?: 'bottom-right',
            'button_label'      => $t['embed_button_label'] ?: __('booking.embed.button_label'),
            'close_label'       => __('booking.embed.close'),
            'frame_title'       => __('booking.embed.frame_title', ['name' => $t['name']]),
            'demo_label'        => __('booking.embed.demo_badge'),
            'requires_consent'  => (bool) $t['requires_consent'],
            'consent_text'      => $t['consent_text'] ?: null,
            'privacy_policy_url'=> $t['privacy_policy_url'] ?: null,
            'custom_fields'     => json_decode($t['custom_fields'] ?? '[]', true) ?: [],
            'allowed_domains'   => $allowedDomains,
            'is_demo'           => DemoMode::isActive(),
        ];

        $response = Response::json($config);
        $response->header('Cache-Control', 'public, max-age=60');
        $response->header('Vary', 'Accept-Language');
        $response->header('Access-Control-Allow-Origin', '*');
        return $response;
    }

    /**
     * GET /embed/{slug}.js
     *
     * Returns a self-contained vanilla JS embed widget.
     * The script:
     * 1. Fetches embed-config from the API
     * 2. Injects a floating "Book Now" button
     * 3. Opens an iframe overlay on click
     * 4. Listens for postMessage completion
     */
    public function script(Request $request): Response
    {
        $slug = $request->getAttribute('slug');

        // Verify tenant exists
        $tenant = Database::query(
            'SELECT `id` FROM `tenants` WHERE `slug` = ? AND `status` = ? LIMIT 1',
            [$slug, 'active']
        );

        if (empty($tenant)) {
            return (new Response())
                ->status(404)
                ->header('Content-Type', 'application/javascript; charset=utf-8')
                ->body('/* VoxelBooking: tenant not found */');
        }

        // Determine the installation origin from the request
        $proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $origin = $proto . '://' . $host;

        $js = $this->generateWidgetScript($slug, $origin);

        return (new Response())
            ->status(200)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600')
            ->header('Access-Control-Allow-Origin', '*')
            ->body($js);
    }

    /**
     * Generate the self-contained embed widget JS.
     */
    private function generateWidgetScript(string $slug, string $origin): string
    {
        $slug   = addslashes($slug);
        $origin = addslashes($origin);

        return <<<JS
(function(){
  "use strict";
  if(window.__vbEmbed){return;}
  window.__vbEmbed=true;

  var ORIGIN="{$origin}";
  var SLUG="{$slug}";
  var CFG_URL=ORIGIN+"/api/"+SLUG+"/embed-config";
  var BOOK_URL=ORIGIN+"/book/"+SLUG+"?embed=1";

  // Fetch config
  fetch(CFG_URL).then(function(r){return r.json()}).then(function(cfg){
    if(!cfg||cfg.error){return;}
    injectButton(cfg);
  }).catch(function(){});

  function injectButton(cfg){
    var btn=document.createElement("button");
    btn.id="vb-embed-btn";
    btn.setAttribute("aria-label",cfg.button_label);

    // Lucide 'calendar' icon as inline SVG (self-contained — Lucide JS not available on host page)
    var iconSvg='<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" '
      +'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0">'
      +'<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/>'
      +'<path d="M3 10h18"/></svg>';
    btn.innerHTML=iconSvg+'<span>'+(cfg.button_label.replace(/</g,"&lt;"))+'</span>';

    var pos=cfg.button_position==="bottom-left"?"left":"right";
    btn.style.cssText="position:fixed;bottom:24px;"+pos+":24px;z-index:99990;"
      +"display:inline-flex;align-items:center;gap:8px;"
      +"padding:12px 22px;border:none;border-radius:50px;cursor:pointer;"
      +"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;"
      +"font-size:14px;font-weight:600;letter-spacing:-0.01em;line-height:1;"
      +"color:"+(cfg.brand_color_text||"#fff")+";"
      +"background:"+(cfg.brand_color||"#4F46E5")+";"
      +"box-shadow:0 2px 8px rgba(0,0,0,0.08),0 8px 24px rgba(0,0,0,0.12);"
      +"transition:transform 200ms cubic-bezier(0.34,1.56,0.64,1),box-shadow 200ms ease,background 150ms ease;"
      +"animation:vb-embed-entrance 500ms cubic-bezier(0.34,1.56,0.64,1) both 600ms;";

    // Inject entrance animation keyframes
    var style=document.createElement("style");
    style.textContent="@keyframes vb-embed-entrance{from{opacity:0;transform:translateY(12px) scale(0.92)}to{opacity:1;transform:translateY(0) scale(1)}}"
      +"#vb-embed-btn:hover{transform:translateY(-2px) scale(1.02)!important;box-shadow:0 4px 12px rgba(0,0,0,0.1),0 12px 32px rgba(0,0,0,0.15)!important}"
      +"#vb-embed-btn:active{transform:translateY(0) scale(0.98)!important;transition-duration:80ms!important}"
      +"#vb-embed-overlay{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;"
      +"background:rgba(0,0,0,0.4);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);animation:vb-embed-fade 250ms ease both}"
      +"@keyframes vb-embed-fade{from{opacity:0}to{opacity:1}}"
      +"#vb-embed-frame-wrap{position:relative;width:calc(100% - 32px);max-width:460px;height:calc(100dvh - 48px);max-height:720px;border-radius:20px;overflow:hidden;"
      +"background:#fff;box-shadow:0 0 0 1px rgba(0,0,0,0.04),0 8px 24px rgba(0,0,0,0.08),0 24px 64px rgba(0,0,0,0.16);"
      +"animation:vb-embed-scale 300ms cubic-bezier(0.32,0.72,0,1) both}"
      +"@keyframes vb-embed-scale{from{opacity:0;transform:scale(0.96) translateY(8px)}to{opacity:1;transform:scale(1) translateY(0)}}"
      +"#vb-embed-close{position:absolute;top:10px;right:10px;width:28px;height:28px;border:none;border-radius:50%;"
      +"background:rgba(0,0,0,0.05);cursor:pointer;display:flex;align-items:center;justify-content:center;color:#888;"
      +"transition:background 150ms ease,color 150ms ease,transform 150ms ease;z-index:2;padding:0}"
      +"#vb-embed-close:hover{background:rgba(0,0,0,0.1);color:#333;transform:scale(1.1)}"
      +"#vb-embed-close:active{transform:scale(0.95)}"
      +"#vb-embed-iframe{width:100%;height:100%;border:none}"
      +"#vb-embed-demo{display:inline-flex;align-items:center;padding:2px 6px;border-radius:4px;"
      +"background:rgba(255,255,255,0.2);font-size:10px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;line-height:1}"
      +"@media(prefers-reduced-motion:reduce){#vb-embed-btn,#vb-embed-overlay,#vb-embed-frame-wrap{animation:none!important}}";
    document.head.appendChild(style);

    // Demo mode indicator
    if(cfg.is_demo){
      var badge=document.createElement("span");
      badge.id="vb-embed-demo";
      badge.textContent=cfg.demo_label;
      btn.appendChild(badge);
    }

    btn.addEventListener("click",function(){openOverlay(cfg)});
    document.body.appendChild(btn);
  }

  function openOverlay(cfg){
    if(document.getElementById("vb-embed-overlay")){return;}

    var overlay=document.createElement("div");
    overlay.id="vb-embed-overlay";

    var wrap=document.createElement("div");
    wrap.id="vb-embed-frame-wrap";
    wrap.style.position="relative";

    var close=document.createElement("button");
    close.id="vb-embed-close";
    // Lucide 'x' icon as inline SVG
    close.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" '
      +'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
      +'<path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    close.setAttribute("aria-label",cfg.close_label);

    var iframe=document.createElement("iframe");
    iframe.id="vb-embed-iframe";
    iframe.src=BOOK_URL;
    iframe.title=cfg.frame_title;
    iframe.setAttribute("loading","eager");

    wrap.appendChild(close);
    wrap.appendChild(iframe);
    overlay.appendChild(wrap);
    document.body.appendChild(overlay);

    function closeOverlay(){
      overlay.style.opacity="0";
      overlay.style.transition="opacity 150ms ease";
      setTimeout(function(){overlay.remove()},160);
    }
    close.addEventListener("click",closeOverlay);
    overlay.addEventListener("click",function(e){
      if(e.target===overlay){closeOverlay()}
    });
    document.addEventListener("keydown",function handler(e){
      if(e.key==="Escape"){closeOverlay();document.removeEventListener("keydown",handler)}
    });

    // Listen for booking completion from iframe
    window.addEventListener("message",function handler(e){
      if(e.origin!==ORIGIN){return;}
      if(e.data&&e.data.type==="booking:confirmed"){
        setTimeout(closeOverlay,1500);
        window.removeEventListener("message",handler);
      }
    });
  }
})();
JS;
    }
}
