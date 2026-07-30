/* Dienstly24 Website - Verhalten (ausgelagert, cachebar).
   Die Sprachwahl ist KEIN JavaScript mehr: DE und AR sind echte URLs
   (/ bzw. /ar), serverseitig gerendert - Google kann beide indexieren. */
(function () {
  var hdr = document.getElementById('hdr');
  if (hdr) {
    addEventListener('scroll', function () { hdr.classList.toggle('scrolled', scrollY > 10); });
  }
  var burger = document.getElementById('burger'), menu = document.getElementById('menu');
  if (burger && menu) {
    burger.addEventListener('click', function () {
      var open = menu.classList.toggle('open');
      burger.setAttribute('aria-expanded', open);
    });
    menu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () { menu.classList.remove('open'); });
    });
  }
  var io = new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
  }, { threshold: .12 });
  document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
  function count(el) {
    var target = +el.getAttribute('data-count'), suf = el.getAttribute('data-suffix') || '', t0 = null;
    function step(ts) {
      if (!t0) t0 = ts;
      var p = Math.min((ts - t0) / 1200, 1);
      var val = Math.floor(p * target);
      el.textContent = (val >= 1000 ? val.toLocaleString('de-DE') : val) + suf;
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  var co = new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { count(e.target); co.unobserve(e.target); } });
  }, { threshold: .5 });
  document.querySelectorAll('.num[data-count]').forEach(function (el) { co.observe(el); });
})();
