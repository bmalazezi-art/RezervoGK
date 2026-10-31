import { t, useLocale } from "../i18n";
import { Head, Link } from "@inertiajs/react";

type PageKey = "howItWorks" | "faq" | "contact" | "privacy" | "terms";
type Section = { title: string; paragraphs: string[]; email?: string };
type Document = { title: string; intro: string; draft?: boolean; review?: string[]; sections: Section[] };

const documents: Record<PageKey, Document> = {
  howItWorks: {
    title: "Si funksionon",
    intro: "Gjej një portier dhe konfirmo ndeshjen drejtpërdrejt me të.",
    sections: [
      { title: "1. Kërko", paragraphs: ["Zgjidh qytetin dhe datën. Përdor filtrat për vendin, monedhën, çmimin maksimal dhe formatin e lojës. Kufiri i çmimit zbatohet vetëm në monedhën e zgjedhur; nuk bëhet konvertim valutor. Kërkimi sipas datës përfshin orarin javor që përsëritet gjatë 30 ditëve të ardhshme dhe datat shtesë të shënuara nga portieri."] },
      { title: "2. Kontakto", paragraphs: ["Hap profilin dhe përdor WhatsApp ose telefonin. Organizatorit nuk i duhet llogari. Konfirmoni datën, orën, vendin, çmimin dhe monedhën mes jush; platforma nuk kryen rezervim automatik."] },
      { title: "3. Luaj", paragraphs: ["Pagesën e ndeshjes ia jep portierit në dorë, sipas marrëveshjes suaj. Platforma nuk mbledh pagesat e ndeshjeve."] },
      { title: "Për portierët", paragraphs: ["Regjistrohu falas, plotëso profilin dhe cakto disponueshmërinë javore një herë. Kërkimi e përsërit atë automatikisht për 30 ditët e ardhshme; shëno ditët që ndryshojnë si të zëna.", "Profilet me pronar që janë të publikuara dhe jo të pezulluara qëndrojnë publike pa afat prove ose pagesë. Profilet demonstrative pa pronar mbeten të fshehura."] },
    ],
  },
  faq: {
    title: "Pyetje të shpeshta — projekt",
    intro: "PROJEKT PËR RISHIKIM. Përgjigje për kërkimin, kontaktin dhe menaxhimin e profilit.",
    draft: true,
    sections: [
      { title: "A më duhet llogari për të gjetur portier?", paragraphs: ["Jo. Organizatorët kërkojnë, shikojnë profilet publike dhe kontaktojnë falas, pa llogari."] },
      { title: "A është konfirmuar ndeshja kur shoh një datë të lirë?", paragraphs: ["Jo. Datat publikohen nga portieri dhe nuk janë rezervime. Konfirmoni orën, vendin, çmimin dhe çdo ndryshim direkt me të."] },
      { title: "Çfarë tregon orari javor?", paragraphs: ["Ditët e zgjedhura përsëriten automatikisht si të lira në kërkim për 30 ditët e ardhshme. Portieri mund të shënojë një ditë të caktuar si të zënë ose të shtojë ditë të tjera. Orari tregon kur zakonisht është i lirë; konfirmoni orën dhe marrëveshjen drejtpërdrejt me portierin."] },
      { title: "Në cilën monedhë shfaqet çmimi?", paragraphs: ["Profilet në Kosovë përdorin EUR. Në Shqipëri monedha fillestare është ALL, me mundësi EUR; në Maqedoninë e Veriut është MKD, me mundësi EUR. Çmimi i publikuar tregon monedhën e zgjedhur nga portieri; çmimet ekzistuese në EUR ruhen.", "Filtri i çmimit zbatohet vetëm brenda monedhës së zgjedhur. Platforma nuk konverton dhe nuk krahason çmime ndërmjet monedhave. Konfirmoni shumën dhe monedhën drejtpërdrejt me portierin. Përdorimi i platformës është falas."] },
      { title: "A verifikohen profilet dhe vlerësimet?", paragraphs: ["Platforma nuk verifikon identitetin, numrin e telefonit ose përvojën e portierit. Nuk ofrohet sistem vlerësimesh. Informacioni i profilit plotësohet nga vetë portieri."] },
      { title: "Si paguhet ndeshja?", paragraphs: ["Pagesa bëhet në dorë direkt mes organizatorit dhe portierit. Platforma nuk merr komision nga ajo pagesë."] },
      { title: "A kushton përdorimi i platformës?", paragraphs: ["Gjatë nisjes falas, përdorimi i platformës është falas për portierët dhe organizatorët. Platforma nuk përpunon rezervime ose pagesa për ndeshjet; këto rregullohen drejtpërdrejt mes palëve."] },
      { title: "Si ta mbroj llogarinë time?", paragraphs: ["Përdor një fjalëkalim të fortë dhe mos ndaj kode hyrjeje. Platforma nuk kërkon transfertë bankare ose dëshmi pagese për ta mbajtur profilin publik."] },
      { title: "Kur mund të mos shfaqet profili im?", paragraphs: ["Profilet pa pronar, të papublikuara ose të pezulluara nuk shfaqen në kërkim ose në lidhjen publike. Skadimi i një prove ose abonimi të mëparshëm nuk e fsheh profilin në modelin falas."] },
      { title: "Si rikthej fjalëkalimin?", paragraphs: ["Përdor lidhjen «Ke harruar fjalëkalimin?» në faqen e kyçjes. Dorëzimi i email-eve duhet të konfigurohet dhe të verifikohet para hapjes publike të shërbimit."] },
      { title: "Çfarë analitike ruan platforma?", paragraphs: ["Për profilet publike ruhen numërime ditore të grumbulluara për shikimet e profilit dhe klikimet në WhatsApp ose telefon. Numërimi kufizohet në një herë për profil, lloj aktiviteti dhe sesion në ditë. Regjistrat e analitikës përmbajnë numra sipas profilit, datës dhe llojit të aktivitetit, jo emra vizitorësh, adresa IP ose të dhëna pajisjeje."] },
      { title: "Çfarë cookies përdor faqja?", paragraphs: ["Aplikacioni përdor cookie-n e sesionit dhe XSRF-TOKEN për sesionet dhe mbrojtjen e kërkesave. Cookie rezervogk_locale ruan gjuhën SQ ose EN për një vit pasi ndërrohet gjuha. Cilësimet e cookie-t të sesionit varen nga konfigurimi i prodhimit dhe duhen verifikuar."] },
      { title: "A mund ta fshij llogarinë time?", paragraphs: ["Po. Nga cilësimet e panelit, zgjidh fshirjen e llogarisë dhe konfirmo veprimin. Profili publik dhe të dhënat personale të lidhura me të hiqen nga sistemet aktive; disa të dhëna financiare dhe auditimi mund të ruhen të anonimizuara."] },
      { title: "A ka moshë minimale për t'u regjistruar?", paragraphs: ["Faqja nuk kërkon datëlindje dhe nuk zbaton kontroll moshe ose pëlqim prindëror. Mosha minimale dhe masat për të miturit duhet të përcaktohen para publikimit."] },
    ],
  },
  contact: {
    title: "Kontakti — projekt",
    intro: "PROJEKT PËR RISHIKIM. Për një ndeshje, kontakto portierin drejtpërdrejt; për mbështetje të platformës ose kërkesa privatësie, përdor email-in më poshtë.",
    draft: true,
    review: [
      "Operatori i dhënë: Bajram Malazezi, individ në Kosovë. Adresa postare nuk është dhënë.",
      "Nuk ka formular ose kanal raportimi të abuzimit brenda aplikacionit; përcakto procedurën dhe afatet e trajtimit.",
    ],
    sections: [
      { title: "Mbështetja dhe privatësia", paragraphs: ["Operatori i platformës është Bajram Malazezi, individ në Kosovë. Për mbështetje, privatësi ose kërkesa lidhur me të dhënat, dërgo email në:"], email: "bmalazezi@gmail.com" },
      { title: "Raportimi dhe pagesat", paragraphs: ["Mund të dërgosh një raport anonim për një profil publik, duke zgjedhur një arsye dhe duke shtuar detaje opsionale. Raportet shqyrtohen nga administratori; dërgimi i raportit nuk e pezullon automatikisht profilin.", "Gjatë nisjes falas, platforma nuk krijon rezervime dhe nuk përpunon pagesa; organizatori dhe portieri merren vesh drejtpërdrejt. Mos dërgo fjalëkalime ose kode hyrjeje me email."] },
    ],
  },
  privacy: {
    title: "Politika e privatësisë",
    intro: "Në fuqi nga 29 tetor 2026. Kjo politikë shpjegon të dhënat që përdor Gjej portier dhe mënyrën si përdoren.",
    sections: [
      { title: "Operatori dhe kontakti", paragraphs: ["Gjej portier operohet nga Bajram Malazezi, individ në Kosovë. Për pyetje rreth kësaj politike ose të dhënave të tua, kontakto:"], email: "bmalazezi@gmail.com" },
      { title: "Të dhënat që mbledhim", paragraphs: ["Kur portieri krijon llogari, platforma ruan emrin, email-in, fjalëkalimin në formë hash-i, numrin e telefonit, vendin dhe qytetin e zgjedhur, përshkrimin, përvojën, gjuhët, çmimin dhe monedhën, formatet e lojës, fotografinë opsionale, orarin javor dhe përjashtimet e datave. Nuk kërkohet datëlindje. Nëse dërgon raport, ruajmë arsyen, detajet opsionale, profilin e raportuar, statusin dhe kohën e raportit. Kërkesat teknike mund të krijojnë regjistra të gabimeve dhe sigurisë."] },
      { title: "Profilet publike", paragraphs: ["Profili publikohet vetëm kur ka pronar të llogarisë, është i publikuar dhe nuk është pezulluar. Vizitorët pa llogari mund të shohin emrin, qytetin, fotografinë, përshkrimin, përvojën, gjuhët, çmimin me monedhën, formatet e lojës, disponueshmërinë dhe numrat e telefonit/WhatsApp-it të publikuar. Email-i i hyrjes dhe fjalëkalimi nuk shfaqen publikisht. Informacioni publik mund të kopjohet ose ruhet nga të tjerët."] },
      { title: "Përdorimi i të dhënave", paragraphs: ["Përdorim të dhënat për të krijuar dhe siguruar llogaritë, për të shfaqur dhe menaxhuar profilet, për të mundësuar kontaktin e drejtpërdrejtë, për të marrë dhe shqyrtuar raportet, për të matur aktivitetin e përgjithshëm të profileve dhe për të zgjidhur probleme teknike. Organizatori kontakton portierin drejtpërdrejt; Gjej portier nuk krijon rezervime dhe nuk përpunon pagesa për ndeshje gjatë nisjes falas."] },
      { title: "Analitika e profilit", paragraphs: ["Për profilet publike numërojmë shikimet dhe klikimet në WhatsApp ose telefon. Tabela ruan numrin ditor sipas profilit, datës dhe llojit të ngjarjes. Një shenjë në sesion kufizon numërimin në një herë për profil, lloj ngjarjeje dhe ditë. Analitika nuk ruan emrin, adresën IP ose të dhënat e pajisjes së vizitorit. Shikimet e pronarit dhe administratorit përjashtohen. Aktualisht nuk është konfiguruar afat fiks ruajtjeje. Regjistrat mbeten të lidhur me profilin dhe fshihen kur fshihet profili."] },
      { title: "Raportet dhe siguria e tyre", paragraphs: ["Çdokush mund të raportojë në mënyrë anonime një profil publik të portierit të regjistruar, duke zgjedhur një arsye dhe, nëse dëshiron, duke dhënë hollësi deri në 1,000 karaktere. Raporti nuk ruan identitetin ose adresën IP të raportuesit. Për të kufizuar abuzimin, kërkesat e raportimit kufizohen sipas një çelësi të prejardhur me hash nga adresa IP për një orë; ky kufizim ruhet përkohësisht. Administratori mund ta shënojë raportin hapur, të shqyrtuar ose të mbyllur; raporti nuk pezullon automatikisht profilin. Aktualisht nuk është konfiguruar afat fiks ruajtjeje për raportet; ato fshihen kur fshihet profili përkatës."] },
      { title: "Cookies dhe teknologjitë e ngjashme", paragraphs: ["Aplikacioni përdor cookie-n e sesionit për hyrjen dhe funksionet e sesionit, XSRF-TOKEN për mbrojtjen e kërkesave dhe rezervogk_locale për të ruajtur gjuhën SQ ose EN deri në një vit pas zgjedhjes. Sesioni mban edhe shenjën e përkohshme të deduplikimit të analitikës. Kodi i aplikacionit nuk përmban cookie reklamimi ose analitike të palës së tretë. Nuk shfaqet baner për preferencat e cookies. Atributet dhe kohëzgjatja e sesionit përcaktohen nga konfigurimi i instalimit."] },
      { title: "Shërbime të jashtme dhe ruajtja", paragraphs: ["Faqja kërkon fonte nga Google Fonts dhe përdor një imazh ilustrues të hostuar nga Unsplash. Kur zgjedh WhatsApp, Viber, Messenger, Instagram ose ndarjen e pajisjes, lidhja dhe teksti që ndan dërgohen te shërbimi që ke zgjedhur. Këto shërbime kanë kushtet dhe praktikat e tyre. Email-i, hostimi, baza e të dhënave dhe ruajtja e fotografive varen nga konfigurimi i mjedisit ku instalohet Gjej portier; këtu nuk emërtojmë një ofrues ose vendndodhje të caktuar. Fotot e profilit ruhen në hapësirë publike të aplikacionit dhe përpunohen për të hequr metadata si EXIF para ruajtjes."] },
      { title: "Ruajtja dhe fshirja e të dhënave", paragraphs: ["Mund ta fshish llogarinë nga cilësimet e panelit pasi të konfirmosh veprimin. Llogaria, profili publik dhe të dhënat e lidhura me profilin, përfshirë raportet dhe analitikën, hiqen nga sistemet aktive. Për të ruajtur historinë e nevojshme financiare dhe të auditimit, mund të mbeten të dhëna të anonimizuara: shumat, datat, statuset, periudhat e abonimit dhe ngjarjet e nevojshme të auditimit. Hiqen lidhjet me llogarinë e fshirë, shënimet personale, referencat bankare dhe pamjet bankare; identifikuesi i administratorit ruhet kur nevojitet për atribuimin e veprimeve të tij. Skedarët e fotografive dhe dëshmive futen për fshirje; nëse ruajtja nuk është e disponueshme, mbeten në radhë për riprovim dhe fshirja fizike mund të vonohet. Kopjet rezervë mund të përmbajnë përkohësisht të dhëna të fshira deri sa ato kopje të zëvendësohen ose fshihen sipas procesit përkatës. Nuk caktojmë këtu një periudhë të caktuar për kopjet rezervë. Regjistrat teknikë dhe të dhënat që ruhen për zgjidhjen e një çështjeje trajtohen sipas nevojës dhe konfigurimit të sistemeve përkatëse."] },
      { title: "Siguria dhe të drejtat e tua", paragraphs: ["Fjalëkalimet ruhen me hash dhe përdoren masa teknike e organizative për të kufizuar qasjen në llogari, raporte dhe dokumente private. Asnjë transmetim ose ruajtje elektronike nuk mund të garantohet si plotësisht e sigurt. Mund të kërkosh qasje, korrigjim ose fshirje të të dhënave duke kontaktuar operatorin. Kërkesat shqyrtohen dhe trajtohen sipas rrethanave dhe rregullave që zbatohen. Përgjigjja mund të kërkojë verifikim të identitetit."] },
    ],
  },
  terms: {
    title: "Kushtet e përdorimit",
    intro: "Në fuqi nga 29 tetor 2026. Duke krijuar llogari ose duke përdorur Gjej portier, pranon këto kushte.",
    sections: [
      { title: "Operatori dhe shërbimi", paragraphs: ["Gjej portier operohet nga Bajram Malazezi, individ në Kosovë. Për ndihmë, shkruaj në bmalazezi@gmail.com. Platforma paraqet profile portierësh dhe u mundëson organizatorëve t'i kontaktojnë drejtpërdrejt. Organizatorët mund të shfletojnë pa llogari."] },
      { title: "Mosha dhe pranimi", paragraphs: ["Duhet të jesh të paktën 18 vjeç për të krijuar ose përdorur llogari portieri. Kur regjistrohesh, duhet të shënosh kutinë që konfirmon moshën dhe pranimin e këtyre kushteve. Dorëzimi i regjistrimit pas këtij konfirmimi përbën pranimin tënd. Nuk kërkojmë datëlindje dhe nuk kryejmë verifikim të pavarur të moshës. Nëse nuk pajtohesh me kushtet, mos krijo llogari dhe mos përdor shërbimet e llogarisë."] },
      { title: "Marrëveshjet dhe disponueshmëria", paragraphs: ["Organizatori dhe portieri janë përgjegjës të konfirmojnë datën, orën, vendin, çmimin, monedhën dhe çdo kusht tjetër mes tyre. Orari javor përsëritet për kërkimet e 30 ditëve të ardhshme dhe portieri mund të shënojë ditë pushimi ose data shtesë. Treguesi i disponueshmërisë është informacion i dhënë nga portieri, jo garanci që ndeshja është e lirë ose e rezervuar."] },
      { title: "Përdorimi dhe përmbajtja e llogarisë", paragraphs: ["Portieri mban përgjegjësi për saktësinë dhe përditësimin e profilit, disponueshmërisë, çmimit dhe kontaktit, si dhe për të drejtën për të publikuar fotografinë dhe tekstin. Mos publiko informacione të rreme, mashtruese ose të dikujt tjetër pa leje; mos ngacmo, kërcëno, mashtro ose ndërhy në funksionimin e shërbimit. Platforma nuk verifikon identitetin, aftësinë apo përvojën e deklaruar dhe nuk ofron sistem vlerësimesh."] },
      { title: "Raportimi dhe masat e moderimit", paragraphs: ["Vizitorët mund të raportojnë profile publike. Administratori shqyrton raportet dhe mund t'i shënojë si hapur, të shqyrtuara ose të mbyllura. Raporti nuk shkakton pezullim automatik. Operatori mund të kufizojë ose heqë profile apo llogari që shkelin këto kushte, rrezikojnë përdoruesit ose dëmtojnë shërbimin. Nuk garantohet afat i caktuar për shqyrtimin e çdo raporti."] },
      { title: "Falasja dhe pagesat", paragraphs: ["Gjatë nisjes falas, përdorimi i platformës nga portierët dhe organizatorët është falas. Platforma nuk kryen rezervime, nuk pranon pagesa për ndeshje dhe nuk merr komision prej tyre. Nëse palët bien dakord për një ndeshje ose pagesë, e rregullojnë drejtpërdrejt jashtë platformës. Çdo tarifë e ardhshme platforme do të kërkojë njoftim dhe kushte të përditësuara përpara se të zbatohet. Të dhënat e vjetra të pagesave, nëse ekzistojnë nga funksione të mëparshme, nuk krijojnë tarifa ose rinovime të reja."] },
      { title: "Llogaria, mbyllja dhe ndërprerja", paragraphs: ["Ti je përgjegjës për ruajtjen e fjalëkalimit dhe për veprimet në llogarinë tënde. Mund ta fshish llogarinë nga cilësimet e panelit pasi të konfirmosh veprimin. Profili publik hiqet nga sistemet aktive dhe të dhënat personale të profilit fshihen; të dhënat e nevojshme financiare dhe të auditimit mund të ruhen në formë të anonimizuar, ndërsa kopjet rezervë mund të përmbajnë përkohësisht të dhëna të fshira deri në zëvendësimin ose fshirjen e tyre. Mund të pezullojmë ose ndërpresim qasjen kur ka shkelje të kushteve ose rrezik të arsyeshëm për përdoruesit/shërbimin."] },
      { title: "Funksionimi dhe përgjegjësia", paragraphs: ["Shërbimi ofrohet në gjendjen dhe disponueshmërinë që ka. Mund të ketë ndërprerje, gabime ose informacion të vjetruar; nuk garantojmë që profili, kontakti apo disponueshmëria janë të sakta ose që një portier do të pranojë ndeshjen. Në masën e lejuar nga rregullat që zbatohen, operatori nuk mban përgjegjësi për marrëveshjet, veprimet, dëmet ose mosmarrëveshjet mes organizatorëve dhe portierëve, apo për ndërprerje dhe humbje që nuk mund të parandalohen në mënyrë të arsyeshme. Asgjë në këto kushte nuk kufizon përgjegjësi që nuk mund të kufizohet sipas ligjit."] },
      { title: "Ndryshimet, ligji dhe mosmarrëveshjet", paragraphs: ["Kushtet e përditësuara publikohen në këtë faqe me datë të re hyrjeje në fuqi. Nëse nuk pajtohesh me një ndryshim, ndërprit përdorimin dhe kërko mbylljen e llogarisë. Këto kushte rregullohen nga ligjet e Kosovës, për aq sa lejohet dhe pa cenuar rregullat detyruese që mund të zbatohen. Palët duhet të përpiqen fillimisht ta zgjidhin mosmarrëveshjen përmes kontaktit në bmalazezi@gmail.com; mosmarrëveshjet e pazgjidhura mund t'u paraqiten gjykatave kompetente në Kosovë, sipas rregullave të zbatueshme."] },
    ],
  }
};

export default function Information({ pageKey }: { pageKey: PageKey }) {
  useLocale();
  const document = documents[pageKey];
  return <main className="page-main dashboard-page information-page policy-page"><Head title={t(document.title)} />
    <div className="section dash-top"><div><span>{document.draft ? t("PROJEKT PËR RISHIKIM") : (pageKey === "privacy" || pageKey === "terms" ? t("POLITIKAT") : t("NDIHMË PËR PËRDORUESIT"))}</span><h1>{t(document.title)}</h1><p>{t(document.intro)}</p></div></div>
    <section className="section information-content policy-content">
      {document.review && <aside className="panel" aria-label={t("Detaje për rishikim nga pronari")}><h2>{t("Detaje për rishikim nga pronari")}</h2><ul>{document.review.map(item => <li key={item}>{t(item)}</li>)}</ul></aside>}
      {document.sections.map(section => <article className="panel information-section policy-section" key={t(section.title)}><h2>{t(section.title)}</h2>{section.paragraphs.map(paragraph => <p key={t(paragraph)}>{t(paragraph)}</p>)}{section.email && <p><a href={`mailto:${section.email}`}>{section.email}</a></p>}</article>)}
      <nav className="chips information-nav" aria-label={t("Faqet e ndihmës dhe politikat")}><Link href="/how-it-works">{t("Si funksionon")}</Link><Link href="/faq">{t("Pyetje të shpeshta")}</Link><Link href="/contact">{t("Kontakti")}</Link><Link href="/privacy">{t("Politika e privatësisë")}</Link><Link href="/terms">{t("Kushtet e përdorimit")}</Link></nav>
    </section>
  </main>;
}
