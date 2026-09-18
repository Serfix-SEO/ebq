SERFIX PUBLISHING KIT
=====================

This kit puts the articles Serfix writes for you on your own website, as real
pages on your own domain.

INSTALL (about 5 minutes)
-------------------------

1. Unzip this file.

2. Upload the two folders, "serfix" and "articles", to the MAIN folder of your
   website - the same folder that holds your home page (often called
   public_html, www or htdocs). Use your hosting control panel's File Manager
   or an FTP program.

   When you are done, these two addresses should open:
       https://your-site.com/serfix/receiver.php
       https://your-site.com/articles/

3. Go back to Serfix and click "Verify & connect".

That's it. New articles appear at https://your-site.com/articles/ as they are
published. Add a link to that page in your site's menu so visitors find them.

GOOD TO KNOW
------------

* serfix/config.php holds your private signing secret. It is what proves an
  article really came from Serfix. Do not share it, and do not reuse this kit
  on another website - download a separate kit for each site.

* Your articles are saved in serfix/data and their images in serfix/media.
  Keep both folders when you move or back up your site.

* Want the articles to use your site's own header and footer? Create
  serfix/header.php and serfix/footer.php. If they exist, they are used
  instead of the simple built-in page. Your header should print the variable
  $serfix_head inside its <head> tag, so each article keeps its SEO tags.

* Tell search engines about your articles by adding this sitemap in Google
  Search Console:  https://your-site.com/articles/?sitemap=1

NEED HELP?
----------

Open a support ticket from your Serfix account and we will walk you through it.
