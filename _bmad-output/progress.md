# Progress - Coach App

## 2026-05-12

- Analyse de la base Capacitor/Vite existante.
- Suppression de l'ecran par defaut Capacitor.
- Mise a jour des metadonnees projet et du README.
- Creation d'une interface mobile simple pour le planning d'entrainement.
- Ajout des pages Accueil, Seances et Calendrier.
- Ajout d'une navigation mobile fixe en bas d'ecran.
- Ajout du formulaire de creation de seance avec titre, date, type, duree et commentaire.
- Sauvegarde des seances en localStorage avec la cle `coach.sessions.v1`.
- Ajout d'un calendrier mensuel simple avec indication des jours contenant des seances.
- Backend non ajoute, conformement au perimetre MVP.

## 2026-05-13

- Transformation de l'ecran de test en application mobile complete branchee sur `style.css` et `app.js`.
- Creation d'un design sombre moderne, mobile-first, compatible Capacitor Android.
- Ajout du dashboard Accueil avec prochaine seance et statistiques rapides.
- Ajout de la liste des seances avec actions modifier et supprimer.
- Ajout du formulaire d'ajout et de modification avec titre, date, type, duree, difficulte et commentaire.
- Passage du stockage local vers la cle `coach.sessions.v2`, avec lecture de compatibilite depuis `coach.sessions.v1`.
- Ajout d'un calendrier mensuel simple et d'une page de statistiques.
- Conservation d'une navigation mobile bottom tabs sans framework.
- Verification du build Vite avec `npm.cmd run build`.

## 2026-05-21

- Recentrage du MVP sur l'usage coach sportif.
- Ajout d'un onboarding avec creation locale obligatoire du compte coach.
- Ajout du dashboard coach avec nom, club, compteur athletes et limite gratuite de 3 athletes.
- Ajout de la creation d'athletes locaux avec prenom, nom, sport, niveau, objectif et commentaire.
- Ajout du blocage au-dela de 3 athletes avec message d'abonnement futur, sans paiement reel.
- Ajout de la fiche athlete avec informations, calendrier propre et liste des seances de cet athlete uniquement.
- Ajout de la creation de seances rattachees a un athlete precis avec titre, date, type, duree, intensite et commentaire.
- Choix technique: stockage unique localStorage `coach.mvp.local.v1`, sans backend, sans authentification reelle et sans secret.
- Garde-fou ajoute: impossible d'ouvrir la creation athlete ou seance sans compte coach local valide.
- Correctif renforce: verrou central `enforceCoachGate()` appele au chargement, rendu, navigation et soumission; formulaire athlete desactive sans coach.
- Limites actuelles: donnees locales au terminal, pas de synchronisation, pas de suppression/modification athlete, pas de paiement.
- Prochaine etape recommandee: ajouter modification/suppression athlete et edition des seances avant toute logique d'abonnement.
