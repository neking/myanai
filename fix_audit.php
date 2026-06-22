<?php
// Run all audit fixes
$fixes = [];

// 1. robots.txt
file_put_contents('/var/www/myanai/robots.txt',
"User-agent: *\nAllow: /\nDisallow: /admin.php\nDisallow: /tenant.php\nDisallow: /db_connect.php\nDisallow: /backup_api.php\nSitemap: https://myanai.net/sitemap.xml\n");
$fixes[] = "✅ robots.txt created";

// 2. sitemap.xml
file_put_contents('/var/www/myanai/sitemap.xml',
'<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://myanai.net/landing-page.html</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>
  <url><loc>https://myanai.net/signup.html</loc><changefreq>monthly</changefreq><priority>0.8</priority></url>
</urlset>');
$fixes[] = "✅ sitemap.xml created";

foreach($fixes as $f) echo $f."\n";
