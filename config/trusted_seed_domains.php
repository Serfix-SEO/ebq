<?php

/*
 * Curated trusted-seed domains for the Trust Score's seed-list component
 * (AuthorityScoreCalculator). TrustRank-style intuition: a backlink profile
 * containing links from editorially-controlled, hard-to-buy sources is very
 * unlikely to be a link farm. Matching is done on the REGISTRABLE domain of
 * each referring domain (subdomains match their parent).
 *
 * Keep this list boring and defensible: institutions, standards bodies,
 * encyclopedias, major newsrooms. NO user-generated-content platforms
 * (reddit/medium/blogspot) — those links are trivially self-placed.
 */

return [
    // Encyclopedias / reference
    'wikipedia.org', 'wikimedia.org', 'wiktionary.org', 'britannica.com',
    'archive.org', 'worldcat.org',

    // Government portals
    'usa.gov', 'whitehouse.gov', 'nasa.gov', 'nih.gov', 'cdc.gov', 'fda.gov',
    'irs.gov', 'sec.gov', 'ftc.gov', 'loc.gov', 'nist.gov', 'noaa.gov',
    'ed.gov', 'energy.gov', 'epa.gov', 'state.gov', 'treasury.gov',
    'gov.uk', 'nhs.uk', 'parliament.uk', 'canada.ca', 'gc.ca',
    'europa.eu', 'ec.europa.eu', 'bund.de', 'gouv.fr', 'gov.au', 'govt.nz',
    'gov.sg', 'gov.in', 'go.jp', 'admin.ch', 'government.nl', 'gov.ie',
    'gov.sa', 'gov.ae',

    // Intergovernmental / NGOs / standards
    'un.org', 'who.int', 'worldbank.org', 'imf.org', 'oecd.org', 'wto.org',
    'unesco.org', 'unicef.org', 'redcross.org', 'icrc.org', 'nato.int',
    'w3.org', 'ietf.org', 'iso.org', 'ieee.org', 'acm.org', 'ansi.org',
    'mozilla.org', 'apache.org', 'linuxfoundation.org', 'wikimediafoundation.org',

    // Universities (registrable domains; subdomain links match)
    'mit.edu', 'harvard.edu', 'stanford.edu', 'berkeley.edu', 'cmu.edu',
    'princeton.edu', 'yale.edu', 'columbia.edu', 'cornell.edu', 'ucla.edu',
    'umich.edu', 'uchicago.edu', 'upenn.edu', 'caltech.edu', 'nyu.edu',
    'ox.ac.uk', 'cam.ac.uk', 'ic.ac.uk', 'ucl.ac.uk', 'ed.ac.uk',
    'ethz.ch', 'epfl.ch', 'u-tokyo.ac.jp', 'nus.edu.sg', 'utoronto.ca',
    'mcgill.ca', 'unimelb.edu.au', 'sydney.edu.au', 'tum.de', 'kuleuven.be',

    // Major newsrooms / wire services
    'reuters.com', 'apnews.com', 'afp.com', 'bbc.com', 'bbc.co.uk',
    'nytimes.com', 'washingtonpost.com', 'wsj.com', 'ft.com', 'economist.com',
    'theguardian.com', 'telegraph.co.uk', 'independent.co.uk',
    'bloomberg.com', 'forbes.com', 'fortune.com', 'time.com', 'theatlantic.com',
    'newyorker.com', 'npr.org', 'pbs.org', 'cnn.com', 'cbsnews.com',
    'nbcnews.com', 'abcnews.go.com', 'usatoday.com', 'latimes.com',
    'lemonde.fr', 'spiegel.de', 'zeit.de', 'faz.net', 'elpais.com',
    'corriere.it', 'asahi.com', 'scmp.com', 'aljazeera.com', 'dw.com',
    'nature.com', 'science.org', 'sciencedirect.com', 'springer.com',
    'nationalgeographic.com', 'scientificamerican.com', 'newscientist.com',

    // High-editorial tech / developer institutions
    'github.com', 'stackoverflow.com', 'developer.mozilla.org', 'php.net',
    'python.org', 'kernel.org', 'debian.org', 'gnu.org',
];
