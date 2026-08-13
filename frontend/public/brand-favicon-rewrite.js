(function() {
  var host = window.location.hostname.toLowerCase();
  if (!(host.endsWith('.localhost') || host === 'localhost')) return;
  var parts = host.split('.');
  var brand = (parts[0] !== 'localhost' && parts[0].length > 0) ? parts[0] : 'rp';
  var files = ['favicon.ico', 'favicon-32x32.png', 'favicon-16x16.png', 'apple-touch-icon.png', 'site.webmanifest', 'safari-pinned-tab.svg'];
  files.forEach(function(f) {
    var el = document.querySelector('link[href="/' + f + '"]');
    if (el) el.href = '/brands/' + brand + '/' + f;
  });
})();
