(function () {
  "use strict";

  if (window.PerigalloAnalytics) return;
  window.PerigalloAnalytics = { version: 1 };

  var storageKey = "perigallo-analytics-consent";
  var visitorKey = "perigallo-analytics-visitor";
  var sessionKey = "perigallo-analytics-session";
  var sessionActivityKey = "perigallo-analytics-activity";
  var queue = [];
  var flushTimer = null;
  var started = false;
  var seenSections = new Set();
  var seenScroll = new Set();

  function id() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (char) {
      var random = Math.floor(Math.random() * 16);
      return (char === "x" ? random : (random & 3) | 8).toString(16);
    });
  }

  function safeStorage(area, key, value) {
    try {
      if (value === undefined) return area.getItem(key);
      area.setItem(key, value);
      return value;
    } catch (error) { return null; }
  }

  function consent() { return safeStorage(window.localStorage, storageKey); }
  function pagePath() { return window.location.pathname || "/"; }
  function pageTitle() { return String(document.title || "Perigallo").slice(0, 180); }

  function experienceSlug() {
    var match = pagePath().match(/^\/(?:eventos|experiencias)\/([a-z0-9-]+)\/?$/i);
    return match ? match[1] : "";
  }

  function pageType() {
    var path = pagePath();
    if (path === "/") return "home";
    if (/^\/(?:eventos|experiencias)\/$/.test(path)) return "experiences";
    if (/^\/(?:eventos|experiencias)\/[a-z0-9-]+\/?$/i.test(path)) return "experience";
    if (/^\/entradas\/checkout/.test(path)) return "checkout";
    if (/^\/entradas\/pedido|^\/entradas\/pago/.test(path)) return "confirmation";
    if (/^\/mis-entradas/.test(path)) return "tickets";
    if (/faq|preguntas/.test(path)) return "faq";
    if (/contacto/.test(path)) return "contact";
    return "other";
  }

  function device() {
    var width = Math.max(window.innerWidth || 0, document.documentElement.clientWidth || 0);
    if (width < 768) return "mobile";
    if (width < 1024) return "tablet";
    return "desktop";
  }

  function traffic() {
    var params = new URLSearchParams(window.location.search);
    var host = "";
    try { host = document.referrer ? new URL(document.referrer).hostname.toLowerCase() : ""; } catch (error) { host = ""; }
    var source = params.get("utm_source") || "";
    if (!source && host) {
      if (/google\./.test(host)) source = "Google";
      else if (/instagram\.com/.test(host)) source = "Instagram";
      else if (/facebook\.com/.test(host)) source = "Facebook";
      else if (/whatsapp\.com|wa\.me/.test(host)) source = "WhatsApp";
      else source = host;
    }
    return {
      source: source || "Directo",
      medium: params.get("utm_medium") || "",
      campaign: params.get("utm_campaign") || "",
      content: params.get("utm_content") || "",
      term: params.get("utm_term") || "",
      referrer_host: host
    };
  }

  function sessionId() {
    var current = safeStorage(window.sessionStorage, sessionKey);
    var lastActivity = Number(safeStorage(window.sessionStorage, sessionActivityKey) || 0);
    if (!current || !lastActivity || Date.now() - lastActivity > 30 * 60 * 1000) {
      current = id();
      safeStorage(window.sessionStorage, sessionKey, current);
    }
    safeStorage(window.sessionStorage, sessionActivityKey, String(Date.now()));
    return current;
  }

  function payload(events) {
    var visitor = safeStorage(window.localStorage, visitorKey);
    if (!visitor) {
      visitor = id();
      safeStorage(window.localStorage, visitorKey, visitor);
    }
    return Object.assign({
      visitor_id: visitor,
      session_id: sessionId(),
      device: device(),
      language: (navigator.language || "").slice(0, 16),
      events: events
    }, traffic());
  }

  function flush() {
    if (!queue.length || consent() !== "granted") return;
    var events = queue.splice(0, 12);
    var body = JSON.stringify(payload(events));
    try {
      if (navigator.sendBeacon && navigator.sendBeacon("/api/analytics/events", new Blob([body], { type: "application/json" }))) return;
      fetch("/api/analytics/events", { method: "POST", credentials: "same-origin", keepalive: true, headers: { "Content-Type": "application/json" }, body: body }).catch(function () {});
    } catch (error) { /* La analítica nunca interrumpe la navegación. */ }
  }

  function queueEvent(name, detail) {
    if (consent() !== "granted") return;
    var event = Object.assign({ name: name, page_path: pagePath(), page_title: pageTitle(), page_type: pageType(), experience_slug: experienceSlug() }, detail || {});
    queue.push(event);
    if (queue.length >= 8) flush();
    else if (!flushTimer) flushTimer = window.setTimeout(function () { flushTimer = null; flush(); }, 1400);
  }

  function sectionName(node, index) {
    if (node.dataset.analyticsSection) return node.dataset.analyticsSection;
    if (node.id) return node.id;
    var heading = node.querySelector("h1,h2,h3");
    var label = String((heading && heading.textContent) || "section-" + (index + 1)).toLowerCase().trim().replace(/[^a-z0-9áéíóúñ]+/gi, "-").replace(/^-|-$/g, "");
    return label.slice(0, 80) || "section-" + (index + 1);
  }

  function observeSections() {
    if (!("IntersectionObserver" in window)) return;
    var nodes = Array.prototype.slice.call(document.querySelectorAll("[data-analytics-section], main section"));
    var visible = new Set();
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting || entry.intersectionRatio < 0.35) {
          visible.delete(entry.target);
          return;
        }
        visible.add(entry.target);
        var section = sectionName(entry.target, nodes.indexOf(entry.target));
        var key = pagePath() + ":" + section;
        if (seenSections.has(key)) return;
        window.setTimeout(function () {
          if (seenSections.has(key) || !visible.has(entry.target)) return;
          seenSections.add(key);
          queueEvent("section_view", { section_id: section });
        }, 700);
      });
    }, { threshold: [0.35, 0.6] });
    nodes.forEach(function (node) { observer.observe(node); });
  }

  function observeScroll() {
    var ticking = false;
    function report() {
      ticking = false;
      var height = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight) - window.innerHeight;
      var percent = height > 0 ? Math.round((window.scrollY / height) * 100) : 100;
      [25, 50, 75, 90, 100].forEach(function (level) {
        var key = pagePath() + ":" + level;
        if (percent >= level && !seenScroll.has(key)) {
          seenScroll.add(key);
          queueEvent("scroll", { scroll_depth: level });
        }
      });
    }
    window.addEventListener("scroll", function () {
      if (!ticking) { ticking = true; window.requestAnimationFrame(report); }
    }, { passive: true });
    report();
  }

  function clickName(target) {
    var node = target.closest("[data-analytics-click], [data-ticket-checkout-link], [data-checkout-submit], a[href*='wa.me'], a[href*='instagram.com']");
    if (!node) return "";
    if (node.dataset.analyticsClick) return node.dataset.analyticsClick;
    if (node.hasAttribute("data-ticket-checkout-link")) return "comprar-entrada";
    if (node.hasAttribute("data-checkout-submit")) return "iniciar-pago";
    var href = node.getAttribute("href") || "";
    if (/wa\.me/.test(href)) return "whatsapp";
    if (/instagram\.com/.test(href)) return "instagram";
    return "";
  }

  function start() {
    if (started || consent() !== "granted") return;
    started = true;
    queueEvent("page_view");
    if (pageType() === "checkout") queueEvent("checkout_start");
    observeSections();
    observeScroll();
    document.addEventListener("click", function (event) {
      var name = clickName(event.target);
      if (!name) return;
      queueEvent("click", { click_id: name });
    }, { capture: true });
    document.addEventListener("submit", function (event) {
      if (event.target && event.target.matches("[data-ticket-checkout]")) queueEvent("payment_start");
    }, { capture: true });
    window.setInterval(function () { queueEvent("session_ping"); }, 60000);
    window.addEventListener("pagehide", flush);
    window.dispatchEvent(new CustomEvent("perigalloanalyticsready"));
  }

  window.PerigalloAnalytics.refresh = function () {
    if (started) observeSections();
  };

  function consentBanner() {
    if (consent()) return start();
    var style = document.createElement("style");
    style.textContent = ".pg-analytics-consent{position:fixed;z-index:120;right:20px;bottom:20px;display:flex;align-items:end;gap:22px;max-width:min(620px,calc(100vw - 40px));padding:18px 20px;border:1px solid rgba(205,177,151,.55);background:#173236;color:#f5f1e5;box-shadow:0 18px 48px rgba(0,0,0,.28);font:400 13px/1.55 Montserrat,Arial,sans-serif}.pg-analytics-consent p{margin:0}.pg-analytics-consent strong{display:block;margin-bottom:4px;color:#e2cdb5;font:400 20px/1 Cormorant Garamond,Georgia,serif}.pg-analytics-consent span{color:rgba(245,241,229,.76)}.pg-analytics-consent div{display:flex;gap:8px;flex:none}.pg-analytics-consent button{border:1px solid rgba(205,177,151,.5);background:transparent;color:#f5f1e5;padding:11px 12px;font:500 10px/1 Montserrat,Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase;cursor:pointer}.pg-analytics-consent button[data-analytics-consent=granted]{border-color:#cdb197;background:#cdb197;color:#173236}@media(max-width:640px){.pg-analytics-consent{right:12px;bottom:12px;left:12px;display:grid;gap:14px;max-width:none}.pg-analytics-consent div{display:grid;grid-template-columns:1fr 1fr}.pg-analytics-consent button{min-height:44px}}";
    document.head.appendChild(style);
    var banner = document.createElement("aside");
    banner.className = "pg-analytics-consent";
    banner.setAttribute("aria-label", "Preferencias de analítica");
    banner.innerHTML = '<p><strong>Mejoramos Perigallo contigo</strong><span>Usamos analítica propia y anónima para entender qué funciona. No vendemos tus datos ni registramos formularios.</span></p><div><button type="button" data-analytics-consent="denied">Solo necesarias</button><button type="button" data-analytics-consent="granted">Aceptar analítica</button></div>';
    document.body.appendChild(banner);
    banner.addEventListener("click", function (event) {
      var button = event.target.closest("[data-analytics-consent]");
      if (!button) return;
      safeStorage(window.localStorage, storageKey, button.dataset.analyticsConsent);
      banner.remove();
      start();
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", consentBanner, { once: true });
  else consentBanner();
})();
