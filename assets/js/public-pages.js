(function () {
  var nav = document.querySelector('[data-public-nav]');
  var toggle = document.querySelector('[data-public-menu]');
  if (nav && toggle) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    nav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  if (!window.PerigalloAnalytics && !document.querySelector('script[data-perigallo-analytics]')) {
    var analytics = document.createElement('script');
    analytics.src = '/assets/js/analytics.js';
    analytics.defer = true;
    analytics.dataset.perigalloAnalytics = 'true';
    document.head.appendChild(analytics);
  }
}());
