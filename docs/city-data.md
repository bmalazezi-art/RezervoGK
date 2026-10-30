# Marketplace city data

The location selectors use municipality-level options for the three supported countries:

- Kosovo: all 38 municipalities.
- Albania: all 61 municipalities.
- North Macedonia: all 80 municipalities, plus the City of Skopje.

These are municipality locations, not a claim to enumerate every village, settlement, neighborhood, or informal place name. Existing city records and goalkeeper profile assignments are retained. The location migration inserts missing rows only; its rollback intentionally leaves location rows in place because profiles may reference them.

Sources:

- [Kosovo Ministry of Local Government Administration — municipality portal](https://mapl.rks-gov.net/portali-i-komunave/)
- [Kosovo Official Gazette — Law on Administrative Municipal Boundaries](https://gzk.rks-gov.net/ActDocumentDetail.aspx?ActID=2518)
- [Albanian Institute of Statistics — Regional Statistical Yearbook 2024](https://www.instat.gov.al/media/vurntwyn/regional-statistical-yearbook-2024-fin.pdf)
- [North Macedonia Ministry of Local Self-Government — municipalities](https://mls.gov.mk/mk-MK/opstini-i-regioni/opstini)
