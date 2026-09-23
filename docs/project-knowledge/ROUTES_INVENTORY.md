# Routen-Inventar (generiert)

Generiert am 23.09.2026 aus `php artisan route:list --json` (509 Routen).
**Nicht von Hand pflegen** - neu erzeugen mit `scripts/wissensbasis-routen.php` (Aufruf siehe Dateikopf).
Schutz: `staff` = role:admin,manager,support,employee; bei mehreren role-Eintraegen gilt der ENGSTE (alle muessen passen).

| Methode | URI | Name | Schutz |
|---|---|---|---|
| GET | `/` | home |  |
| GET | `abmelden/{token}` | unsubscribe | throttle:30,1 |
| POST | `abmelden/{token}` | unsubscribe.oneclick | throttle:30,1 |
| GET | `admin` | admin.dashboard | auth role:staff |
| GET | `admin/activity-log` | admin.activity_log | auth role:staff role:admin,manager |
| GET | `admin/aktivitaet` | admin.activity.index | auth role:staff role:admin,manager |
| GET | `admin/aktivitaet/einstellungen` | admin.activity.settings | auth role:staff role:admin,manager role:admin |
| PUT | `admin/aktivitaet/einstellungen` | admin.activity.settings.update | auth role:staff role:admin,manager role:admin |
| GET | `admin/aktivitaet/export` | admin.activity.export | auth role:staff role:admin,manager |
| GET | `admin/aktivitaet/{id}` | admin.activity.show | auth role:staff role:admin,manager |
| GET | `admin/aktivitaet/{id}/export` | admin.activity.user_export | auth role:staff role:admin,manager |
| GET | `admin/announcements` | admin.announcements | auth role:staff |
| POST | `admin/announcements` | admin.announcements.store | auth role:staff |
| DELETE | `admin/announcements/{id}` | admin.announcements.destroy | auth role:staff |
| GET | `admin/appointments` | admin.appointments | auth role:staff |
| POST | `admin/appointments` | admin.appointments.store | auth role:staff |
| PUT | `admin/appointments/{id}` | admin.appointments.update | auth role:staff |
| GET | `admin/attachments/{id}/download` | admin.attachment.download | auth role:staff |
| GET | `admin/banners` | admin.banners | auth role:staff role:admin,manager |
| POST | `admin/banners` | admin.banners.store | auth role:staff role:admin,manager |
| GET | `admin/banners/statistik` | admin.banners.stats | auth role:staff role:admin,manager |
| POST | `admin/banners/{banner}` | admin.banners.update | auth role:staff role:admin,manager |
| POST | `admin/banners/{banner}/delete` | admin.banners.delete | auth role:staff role:admin,manager |
| POST | `admin/banners/{banner}/move` | admin.banners.move | auth role:staff role:admin,manager |
| POST | `admin/banners/{banner}/reset-stats` | admin.banners.reset_stats | auth role:staff role:admin,manager |
| GET | `admin/banners/{banner}/social` | admin.banners.social | auth role:staff role:admin,manager |
| POST | `admin/banners/{banner}/social` | admin.banners.social.save | auth role:staff role:admin,manager |
| GET | `admin/banners/{banner}/social/paket` | admin.banners.social.zip | auth role:staff role:admin,manager |
| POST | `admin/banners/{banner}/social/zahlen` | admin.banners.social.refresh_insights | auth role:staff role:admin,manager |
| POST | `admin/banners/{banner}/social/{platform}/api-post` | admin.banners.social.publish_now | auth role:staff role:admin,manager |
| POST | `admin/banners/{banner}/social/{platform}/veroeffentlicht` | admin.banners.social.published | auth role:staff role:admin,manager |
| POST | `admin/banners/{banner}/toggle` | admin.banners.toggle | auth role:staff role:admin,manager |
| GET | `admin/change-requests` | admin.change_requests | auth role:staff |
| GET | `admin/change-requests/nachweis/{id}` | admin.change_requests.proof | auth role:staff |
| POST | `admin/change-requests/{id}/action` | admin.change_requests.action | auth role:staff |
| GET | `admin/change-requests/{id}/document` | admin.change_requests.document | auth role:staff |
| GET | `admin/change-requests/{id}/mitteilungen` | admin.change_requests.notifications | auth role:staff |
| POST | `admin/change-requests/{id}/nachweis-pruefen` | admin.change_requests.recheck | auth role:staff |
| POST | `admin/change-requests/{id}/rueckfrage` | admin.change_requests.ask | auth role:staff |
| GET | `admin/chat` | admin.chat.index | auth role:staff |
| POST | `admin/chat` | admin.chat.store | auth role:staff |
| GET | `admin/chat/{id}` | admin.chat.show | auth role:staff |
| POST | `admin/chat/{id}/reply` | admin.chat.reply | auth role:staff |
| GET | `admin/commissions` | admin.commissions | auth role:staff role:admin,manager |
| POST | `admin/commissions/{id}/book` | admin.commissions.book | auth role:staff role:admin,manager |
| POST | `admin/commissions/{id}/reject` | admin.commissions.reject | auth role:staff role:admin,manager |
| GET | `admin/contracts` | admin.contracts | auth role:staff |
| GET | `admin/contracts/create/{customerId}` | admin.contract.create | auth role:staff |
| GET | `admin/contracts/new` | admin.contract.new | auth role:staff |
| POST | `admin/contracts/{customerId}` | admin.contract.store | auth role:staff |
| PUT | `admin/contracts/{id}` | admin.contract.update | auth role:staff |
| DELETE | `admin/contracts/{id}` | admin.contract.destroy | auth role:staff |
| GET | `admin/contracts/{id}/edit` | admin.contract.edit | auth role:staff |
| POST | `admin/contracts/{id}/switch-responded` | admin.contracts.switch_responded | auth role:staff |
| POST | `admin/contracts/{id}/zaehlerstand` | admin.contract.meter_reading.store | auth role:staff |
| DELETE | `admin/contracts/{id}/zaehlerstand/{readingId}` | admin.contract.meter_reading.destroy | auth role:staff role:admin,manager |
| GET | `admin/customers` | admin.customers | auth role:staff |
| POST | `admin/customers` | admin.customers.store | auth role:staff |
| POST | `admin/customers/bulk-assign` | admin.customers.bulk-assign | auth role:staff role:admin,manager |
| POST | `admin/customers/bulk-delete` | admin.customers.bulk-delete | auth role:staff role:admin |
| GET | `admin/customers/create` | admin.customers.create | auth role:staff |
| GET | `admin/customers/duplicates` | admin.customers.duplicates | auth role:staff |
| POST | `admin/customers/duplicates/dismiss` | admin.customers.duplicates.dismiss | auth role:staff |
| POST | `admin/customers/duplicates/dismiss-bulk` | admin.customers.duplicates.dismiss_bulk | auth role:staff |
| POST | `admin/customers/duplicates/merge` | admin.customers.duplicates.merge | auth role:staff role:admin,manager |
| POST | `admin/customers/duplicates/merge-all` | admin.customers.duplicates.merge_all | auth role:staff role:admin,manager |
| DELETE | `admin/customers/family/{id}` | admin.customer.family.delete | auth role:staff |
| PUT | `admin/customers/notes/{id}/done` | admin.customer.note.done | auth role:staff |
| GET | `admin/customers/relationships` | admin.customers.relationships | auth role:staff |
| DELETE | `admin/customers/relationships/{id}` | admin.customers.relationships.delete | auth role:staff |
| POST | `admin/customers/relationships/{id}/type` | admin.customers.relationships.type | auth role:staff |
| POST | `admin/customers/{customerId}/document-requests` | admin.document_requests.store | auth role:staff |
| GET | `admin/customers/{id}` | admin.customer | auth role:staff |
| PUT | `admin/customers/{id}` | admin.customer.update | auth role:staff |
| DELETE | `admin/customers/{id}` | admin.customers.delete | auth role:staff role:admin |
| POST | `admin/customers/{id}/betreuer` | admin.customers.betreuer | auth role:staff role:admin,manager |
| POST | `admin/customers/{id}/documents` | admin.customer.document.store | auth role:staff |
| GET | `admin/customers/{id}/edit` | admin.customer.edit | auth role:staff |
| GET | `admin/customers/{id}/familie/kunden-suche` | admin.customer.family.search | auth role:staff |
| POST | `admin/customers/{id}/familie/verknuepfen` | admin.customer.family.link | auth role:staff |
| DELETE | `admin/customers/{id}/familie/{relation}` | admin.customer.family.unlink | auth role:staff |
| POST | `admin/customers/{id}/familie/{relation}/rolle` | admin.customer.family.role | auth role:staff |
| POST | `admin/customers/{id}/family` | admin.customer.family.store | auth role:staff |
| POST | `admin/customers/{id}/internal-messages` | admin.internal.store | auth role:staff |
| GET | `admin/customers/{id}/merge` | admin.customer.merge | auth role:staff role:admin |
| POST | `admin/customers/{id}/merge` | admin.customer.merge.do | auth role:staff role:admin |
| POST | `admin/customers/{id}/messages` | admin.customer.messages.store | auth role:staff |
| POST | `admin/customers/{id}/notes` | admin.customer.note.store | auth role:staff |
| POST | `admin/customers/{id}/portal/invite` | admin.customer.portal.invite | auth role:staff role:admin |
| POST | `admin/customers/{id}/portal/reset` | admin.customer.portal.reset | auth role:staff role:admin |
| POST | `admin/customers/{id}/portal/reset-link` | admin.customer.portal.reset_link | auth role:staff role:admin |
| POST | `admin/customers/{id}/portal/toggle` | admin.customer.portal.toggle | auth role:staff role:admin |
| GET | `admin/customers/{id}/timeline` | admin.customer.timeline | auth role:staff |
| POST | `admin/customers/{id}/vehicles` | admin.customer.vehicle.store | auth role:staff |
| GET | `admin/document-requests` | admin.document_requests | auth role:staff |
| POST | `admin/document-requests/{id}/approve` | admin.document_requests.approve | auth role:staff |
| POST | `admin/document-requests/{id}/reject` | admin.document_requests.reject | auth role:staff |
| POST | `admin/documents/batch-preview` | admin.documents.batch_preview | auth role:staff throttle:1200,1 |
| POST | `admin/documents/bulk-delete` | admin.documents.bulk_delete | auth role:staff throttle:300,10 |
| POST | `admin/documents/create-customer-batch` | admin.documents.create_customer_batch | auth role:staff throttle:300,10 |
| GET | `admin/documents/customer-search` | admin.documents.customer_search | auth role:staff throttle:600,1 |
| POST | `admin/documents/smart-upload` | admin.documents.smart_upload | auth role:staff throttle:300,10 |
| GET | `admin/documents/{id}` | admin.documents.show | auth role:staff |
| PUT | `admin/documents/{id}` | admin.documents.update | auth role:staff |
| DELETE | `admin/documents/{id}` | admin.documents.destroy | auth role:staff |
| GET | `admin/documents/{id}/analyse-status` | admin.documents.analyse_status | auth role:staff throttle:2400,1 |
| POST | `admin/documents/{id}/assign` | admin.documents.assign | auth role:staff throttle:300,10 |
| POST | `admin/documents/{id}/create-customer` | admin.documents.create_customer | auth role:staff throttle:300,10 |
| POST | `admin/documents/{id}/create-customers-from-persons` | admin.documents.create_customers_persons | auth role:staff throttle:300,10 |
| GET | `admin/documents/{id}/download` | admin.documents.download | auth role:staff |
| GET | `admin/documents/{id}/erkannter-text` | admin.documents.ocr_text | auth role:staff throttle:120,10 |
| GET | `admin/documents/{id}/kunden-vorschlaege` | admin.documents.customer_suggestions | auth role:staff throttle:600,1 |
| POST | `admin/documents/{id}/reanalyze` | admin.documents.reanalyze | auth role:staff throttle:300,10 |
| POST | `admin/documents/{id}/replace` | admin.documents.replace | auth role:staff |
| GET | `admin/dokumenten-eingang` | admin.documents.inbox | auth role:staff |
| GET | `admin/email-accounts` | admin.email_accounts.index | auth role:staff role:admin |
| POST | `admin/email-accounts` | admin.email_accounts.store | auth role:staff role:admin |
| GET | `admin/email-accounts/create` | admin.email_accounts.create | auth role:staff role:admin |
| GET | `admin/email-accounts/oauth/callback` | admin.email_accounts.oauth_callback | auth role:staff role:admin |
| PUT | `admin/email-accounts/{id}` | admin.email_accounts.update | auth role:staff role:admin |
| DELETE | `admin/email-accounts/{id}` | admin.email_accounts.destroy | auth role:staff role:admin |
| GET | `admin/email-accounts/{id}/edit` | admin.email_accounts.edit | auth role:staff role:admin |
| GET | `admin/email-accounts/{id}/oauth` | admin.email_accounts.oauth | auth role:staff role:admin |
| POST | `admin/email-accounts/{id}/test` | admin.email_accounts.test | auth role:staff role:admin |
| PUT | `admin/email-accounts/{id}/toggle` | admin.email_accounts.toggle | auth role:staff role:admin |
| GET | `admin/email-inbox` | admin.email_inbox | auth role:staff role:admin,manager,support |
| POST | `admin/email-inbox/ai/{decisionId}/accept` | admin.email_inbox.ai_accept | auth role:staff role:admin,manager,support |
| POST | `admin/email-inbox/ai/{decisionId}/reject` | admin.email_inbox.ai_reject | auth role:staff role:admin,manager,support |
| GET | `admin/email-inbox/{id}` | admin.email_inbox.show | auth role:staff role:admin,manager,support |
| POST | `admin/email-inbox/{id}/assign` | admin.email_inbox.assign | auth role:staff role:admin,manager,support |
| GET | `admin/email-inbox/{id}/attachment/{index}` | admin.email_inbox.attachment | auth role:staff role:admin,manager,support |
| POST | `admin/email-inbox/{id}/confirm` | admin.email_inbox.confirm | auth role:staff role:admin,manager,support |
| POST | `admin/email-inbox/{id}/reject` | admin.email_inbox.reject | auth role:staff role:admin,manager,support |
| GET | `admin/email-marketing` | admin.email_marketing | auth role:staff |
| POST | `admin/email-marketing/preview` | admin.email_marketing.preview | auth role:staff |
| POST | `admin/email-marketing/reminders` | admin.email_marketing.reminders | auth role:staff |
| POST | `admin/email-marketing/send` | admin.email_marketing.send | auth role:staff |
| POST | `admin/email-marketing/test` | admin.email_marketing.test | auth role:staff |
| DELETE | `admin/email-marketing/{id}` | admin.email_marketing.destroy | auth role:staff |
| POST | `admin/email-marketing/{id}/dispatch` | admin.email_marketing.dispatch | auth role:staff |
| POST | `admin/email/favorit/{id}` | admin.email.favorite | auth role:staff |
| POST | `admin/email/ki-entwurf` | admin.email.ai_draft | auth role:staff throttle:15,10 |
| GET | `admin/email/kunden-kontext/{id}` | admin.email.customer_context | auth role:staff throttle:120,1 |
| GET | `admin/email/kunden-suche` | admin.email.customer_search | auth role:staff throttle:120,1 |
| GET | `admin/email/verfassen` | admin.email.compose | auth role:staff |
| POST | `admin/email/verfassen` | admin.email.compose.send | auth role:staff |
| GET | `admin/employees` | admin.employees | auth role:staff role:admin,manager |
| POST | `admin/employees` | admin.employees.store | auth role:staff role:admin,manager |
| GET | `admin/employees/create` | admin.employees.create | auth role:staff role:admin,manager |
| GET | `admin/employees/customer-search` | admin.employees.customer-search | auth role:staff role:admin,manager |
| GET | `admin/employees/{id}` | admin.employees.show | auth role:staff role:admin,manager |
| PUT | `admin/employees/{id}` | admin.employees.update | auth role:staff role:admin,manager |
| DELETE | `admin/employees/{id}` | admin.employees.destroy | auth role:staff role:admin |
| POST | `admin/employees/{id}/assign-customers` | admin.employees.assign_customers | auth role:staff role:admin,manager |
| DELETE | `admin/employees/{id}/customers/{customerId}` | admin.employees.unassign_customer | auth role:staff role:admin,manager |
| GET | `admin/employees/{id}/edit` | admin.employees.edit | auth role:staff role:admin,manager |
| POST | `admin/employees/{id}/einladung` | admin.employees.resend_invitation | auth role:staff role:admin,manager |
| PUT | `admin/employees/{id}/toggle-active` | admin.employees.toggle | auth role:staff role:admin,manager |
| POST | `admin/employees/{id}/zwei-faktor-zuruecksetzen` | admin.employees.reset_two_factor | auth role:staff role:admin |
| GET | `admin/export` | admin.export | auth role:staff role:admin,manager |
| GET | `admin/familie/uebergaenge` | admin.family.transitions | auth role:staff |
| POST | `admin/familie/uebergaenge/{relation}/vorbereiten` | admin.family.prepare_transition | auth role:staff |
| GET | `admin/fehler` | admin.errors | auth role:staff role:admin,manager |
| POST | `admin/fehler/{id}/erledigt` | admin.errors.resolve | auth role:staff role:admin,manager |
| POST | `admin/fehler/{id}/wieder-oeffnen` | admin.errors.reopen | auth role:staff role:admin,manager |
| GET | `admin/firmensignaturen` | admin.signatures.company.index | auth role:staff |
| POST | `admin/firmensignaturen` | admin.signatures.company.store | auth role:staff |
| DELETE | `admin/firmensignaturen/{id}` | admin.signatures.company.destroy | auth role:staff |
| GET | `admin/firmensignaturen/{id}/bild` | admin.signatures.company.image | auth role:staff |
| POST | `admin/firmensignaturen/{id}/standard` | admin.signatures.company.default | auth role:staff |
| POST | `admin/import` | admin.import | auth role:staff role:admin,manager |
| GET | `admin/import-export` | admin.import_export | auth role:staff role:admin,manager |
| POST | `admin/import/confirm` | admin.import.confirm | auth role:staff role:admin,manager |
| GET | `admin/import/template` | admin.import.template | auth role:staff role:admin,manager |
| GET | `admin/inquiries` | admin.inquiries | auth role:staff role:admin,manager,support |
| POST | `admin/inquiries` | admin.inquiries.store | auth role:staff role:admin,manager,support |
| GET | `admin/inquiries/create` | admin.inquiries.create | auth role:staff role:admin,manager,support |
| GET | `admin/interessenten` | admin.leads.index | auth role:staff |
| DELETE | `admin/internal-messages/{id}` | admin.internal.destroy | auth role:staff |
| GET | `admin/interne-provisionen` | admin.commissions_internal.index | auth role:staff can:provisionen-verwalten |
| GET | `admin/interne-provisionen/export` | admin.commissions_internal.export | auth role:staff can:provisionen-verwalten |
| GET | `admin/interne-provisionen/import` | admin.commissions_internal.import | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/import` | admin.commissions_internal.upload | auth role:staff can:provisionen-verwalten |
| GET | `admin/interne-provisionen/import/{id}` | admin.commissions_internal.preview | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/import/{id}/bestaetigen` | admin.commissions_internal.confirm | auth role:staff can:provisionen-verwalten |
| GET | `admin/interne-provisionen/import/{id}/fehler.csv` | admin.commissions_internal.errors | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/import/{id}/verwerfen` | admin.commissions_internal.discard | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/import/{id}/zuordnung` | admin.commissions_internal.remap | auth role:staff can:provisionen-verwalten |
| GET | `admin/interne-provisionen/protokoll` | admin.commissions_internal.audit | auth role:staff can:provisionen-verwalten |
| GET | `admin/interne-provisionen/rechnungsabgleich` | admin.commissions_internal.invoice | auth role:staff can:provisionen-verwalten |
| GET | `admin/interne-provisionen/vertrag-suche` | admin.commissions_internal.contract_search | auth role:staff can:provisionen-verwalten |
| GET | `admin/interne-provisionen/{id}` | admin.commissions_internal.show | auth role:staff can:provisionen-verwalten |
| PUT | `admin/interne-provisionen/{id}` | admin.commissions_internal.update | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/{id}/rechnung` | admin.commissions_internal.invoice_link | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/{id}/rechnung-loesen` | admin.commissions_internal.invoice_unlink | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/{id}/status` | admin.commissions_internal.status | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/{id}/zahlung` | admin.commissions_internal.pay | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/{id}/zuordnen` | admin.commissions_internal.link | auth role:staff can:provisionen-verwalten |
| POST | `admin/interne-provisionen/{id}/zuordnung-loesen` | admin.commissions_internal.unlink | auth role:staff can:provisionen-verwalten |
| GET | `admin/kanaele` | admin.channels.index | auth role:staff role:admin |
| PUT | `admin/kanaele/ki-betriebsart` | admin.channels.ai_mode | auth role:staff role:admin |
| PUT | `admin/kanaele/konten/{id}` | admin.channels.accounts.update | auth role:staff role:admin |
| PUT | `admin/kanaele/konten/{id}/anbindungsart` | admin.channels.accounts.connection_type | auth role:staff role:admin |
| POST | `admin/kanaele/konten/{id}/test` | admin.channels.accounts.test | auth role:staff role:admin |
| POST | `admin/kanaele/konten/{id}/trennen` | admin.channels.accounts.disconnect | auth role:staff role:admin |
| POST | `admin/kanaele/whatsapp/verbinden` | admin.channels.whatsapp.complete | auth role:staff role:admin |
| POST | `admin/kanaele/{channelId}/konten` | admin.channels.accounts.store | auth role:staff role:admin |
| PUT | `admin/kanaele/{id}` | admin.channels.update | auth role:staff role:admin |
| GET | `admin/ki-anbieter` | admin.ai_providers.index | auth role:staff role:admin |
| POST | `admin/ki-anbieter` | admin.ai_providers.store | auth role:staff role:admin |
| PUT | `admin/ki-anbieter/geschaeftszeiten` | admin.ai_providers.hours | auth role:staff role:admin |
| PUT | `admin/ki-anbieter/textbausteine` | admin.ai_providers.texts | auth role:staff role:admin |
| PUT | `admin/ki-anbieter/{id}` | admin.ai_providers.update | auth role:staff role:admin |
| DELETE | `admin/ki-anbieter/{id}` | admin.ai_providers.destroy | auth role:staff role:admin |
| POST | `admin/ki-anbieter/{id}/test` | admin.ai_providers.test | auth role:staff role:admin |
| POST | `admin/ki-assistent/{id}/aktivieren` | admin.ai_assistant.reactivate | auth role:staff |
| POST | `admin/ki-assistent/{id}/angebot` | admin.ai_assistant.offer.store | auth role:staff |
| DELETE | `admin/ki-assistent/{id}/angebot/{offer}` | admin.ai_assistant.offer.destroy | auth role:staff |
| GET | `admin/ki-assistent/{id}/antwortvorschlag` | admin.ai_assistant.suggest | auth role:staff |
| POST | `admin/ki-assistent/{id}/deaktivieren` | admin.ai_assistant.deactivate | auth role:staff |
| POST | `admin/ki-assistent/{id}/erneut-versuchen` | admin.ai_assistant.retry | auth role:staff |
| POST | `admin/ki-assistent/{id}/uebernehmen` | admin.ai_assistant.take_over | auth role:staff |
| GET | `admin/ki-training` | admin.ki_training | auth role:staff role:admin,manager |
| POST | `admin/ki-training` | admin.ki_training.store | auth role:staff role:admin,manager |
| POST | `admin/ki-training/beispiel/{id}` | admin.ki_training.review | auth role:staff role:admin,manager |
| PUT | `admin/ki-training/{id}` | admin.ki_training.update | auth role:staff role:admin,manager |
| POST | `admin/ki-training/{id}/uebernehmen` | admin.ki_training.confirm | auth role:staff role:admin,manager |
| POST | `admin/ki-training/{id}/verwerfen` | admin.ki_training.discard | auth role:staff role:admin,manager |
| GET | `admin/ki-wissensbasis` | admin.ai_knowledge | auth role:staff role:admin,manager |
| POST | `admin/ki-wissensbasis` | admin.ai_knowledge.store | auth role:staff role:admin,manager |
| POST | `admin/ki-wissensbasis/import` | admin.ai_knowledge.import | auth role:staff role:admin,manager |
| POST | `admin/ki-wissensbasis/sammelaktion` | admin.ai_knowledge.bulk | auth role:staff role:admin,manager |
| PUT | `admin/ki-wissensbasis/{id}` | admin.ai_knowledge.update | auth role:staff role:admin,manager |
| DELETE | `admin/ki-wissensbasis/{id}` | admin.ai_knowledge.destroy | auth role:staff role:admin,manager |
| GET | `admin/ki-wissensluecken` | admin.ai_knowledge_gaps | auth role:staff role:admin,manager |
| POST | `admin/ki-wissensluecken/{id}/antwort` | admin.ai_knowledge_gaps.answer | auth role:staff role:admin,manager |
| POST | `admin/ki-wissensluecken/{id}/status` | admin.ai_knowledge_gaps.status | auth role:staff role:admin,manager |
| GET | `admin/kunden-suche` | admin.customers.search | auth role:staff |
| GET | `admin/kundenchat` | admin.customer_chat | auth role:staff |
| GET | `admin/kundenchat/{id}/feed` | admin.customer_chat.feed | auth role:staff throttle:120,1 |
| POST | `admin/kundenchat/{id}/ticket` | admin.customer_chat.ticket | auth role:staff |
| GET | `admin/lexoffice/contacts` | admin.lexoffice.contacts | auth role:staff role:admin,manager |
| POST | `admin/lexoffice/contacts/import` | admin.lexoffice.import | auth role:staff role:admin,manager |
| GET | `admin/lexoffice/invoices` | admin.lexoffice.invoices | auth role:staff role:admin,manager |
| GET | `admin/lexoffice/invoices/{id}/download` | admin.lexoffice.invoice.download | auth role:staff role:admin,manager |
| POST | `admin/lexoffice/invoices/{id}/send` | admin.lexoffice.invoice.send | auth role:staff role:admin,manager |
| GET | `admin/medien` | admin.media | auth role:staff |
| POST | `admin/medien` | admin.media.store | auth role:staff |
| PUT | `admin/medien/{asset}` | admin.media.update | auth role:staff |
| DELETE | `admin/medien/{asset}` | admin.media.delete | auth role:staff role:admin,manager |
| POST | `admin/medien/{asset}/ersetzen` | admin.media.replace | auth role:staff |
| POST | `admin/medien/{id}/wiederherstellen` | admin.media.restore | auth role:staff role:admin,manager |
| GET | `admin/messages/attachments/{id}/download` | admin.messages.attachment | auth role:staff |
| GET | `admin/messages/attachments/{id}/view` | admin.messages.attachment.view | auth role:staff |
| POST | `admin/mitteilungen/{id}` | admin.change_notifications.update | auth role:staff |
| POST | `admin/mitteilungen/{id}/erledigt` | admin.change_notifications.skip | auth role:staff |
| POST | `admin/mitteilungen/{id}/senden` | admin.change_notifications.send | auth role:staff |
| GET | `admin/notifications` | admin.notifications | auth role:staff |
| POST | `admin/notifications/read-all` | admin.notifications.read_all | auth role:staff |
| POST | `admin/notifications/{id}/read` | admin.notifications.read | auth role:staff |
| GET | `admin/partners` | admin.partners | auth role:staff role:admin,manager |
| POST | `admin/partners` | admin.partners.store | auth role:staff role:admin,manager |
| GET | `admin/partners/{id}` | admin.partners.show | auth role:staff role:admin,manager |
| PUT | `admin/partners/{id}` | admin.partners.update | auth role:staff role:admin,manager |
| GET | `admin/postfach` | admin.postfach | auth role:staff |
| POST | `admin/postfach-anhang/{id}/in-die-akte` | admin.postfach.file_attachment | auth role:staff |
| POST | `admin/postfach/{id}/antwort` | admin.postfach.reply | auth role:staff |
| POST | `admin/postfach/{id}/kanal-trennen` | admin.postfach.detach_channel | auth role:staff |
| POST | `admin/postfach/{id}/kunde-anlegen` | admin.postfach.create_customer | auth role:staff |
| POST | `admin/postfach/{id}/kunde-verknuepfen` | admin.postfach.link_customer | auth role:staff |
| POST | `admin/postfach/{id}/notiz` | admin.postfach.note | auth role:staff |
| POST | `admin/postfach/{id}/uebernehmen` | admin.postfach.take_over | auth role:staff |
| POST | `admin/postfach/{id}/zuordnung-bestaetigen` | admin.postfach.confirm_identity | auth role:staff |
| POST | `admin/postfach/{id}/zustand` | admin.postfach.status | auth role:staff |
| POST | `admin/postfach/{id}/zuweisen` | admin.postfach.reassign | auth role:staff |
| GET | `admin/provisionen` | admin.provisions | auth role:staff role:admin,manager |
| POST | `admin/provisionen` | admin.provisions.store | auth role:staff role:admin,manager |
| GET | `admin/provisionen/bericht` | admin.provisions.report | auth role:staff role:admin,manager |
| GET | `admin/provisionen/bericht/export` | admin.provisions.report.export | auth role:staff role:admin,manager |
| GET | `admin/provisionen/dashboard` | admin.provisions.dashboard | auth role:staff role:admin,manager |
| GET | `admin/provisionen/saetze` | admin.provisions.rates | auth role:staff role:admin,manager |
| POST | `admin/provisionen/saetze` | admin.provisions.rates.save | auth role:staff role:admin,manager |
| GET | `admin/provisionen/{id}` | admin.provisions.show | auth role:staff role:admin,manager |
| POST | `admin/provisionen/{id}/betrag` | admin.provisions.amount | auth role:staff role:admin,manager |
| POST | `admin/provisionen/{id}/status` | admin.provisions.status | auth role:staff role:admin,manager |
| GET | `admin/provisionsmanagement` | admin.provisionsmanagement.dashboard | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/abrechnungen` | admin.provisionsmanagement.statements | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/auswertungen` | admin.provisionsmanagement.analytics | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/auswertungen/export.csv` | admin.provisionsmanagement.export | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/einstellungen` | admin.provisionsmanagement.settings | auth role:staff can:provisionen-verwalten |
| POST | `admin/provisionsmanagement/einstellungen/pool` | admin.provisionsmanagement.pool_store | auth role:staff can:provisionen-verwalten |
| PUT | `admin/provisionsmanagement/einstellungen/pool/{id}` | admin.provisionsmanagement.pool_update | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/fehlende-provisionen` | admin.provisionsmanagement.missing | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/importe` | admin.provisionsmanagement.imports | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/kunde/{id}` | admin.provisionsmanagement.customer | auth role:staff can:provisionen-verwalten |
| POST | `admin/provisionsmanagement/neu-berechnen` | admin.provisionsmanagement.recalculate | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/unklare-zuordnungen` | admin.provisionsmanagement.unclear | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/vertraege` | admin.provisionsmanagement.contracts | auth role:staff can:provisionen-verwalten |
| GET | `admin/provisionsmanagement/vertrag/{id}` | admin.provisionsmanagement.contract | auth role:staff can:provisionen-verwalten |
| POST | `admin/provisionsmanagement/vertrag/{id}/nachverfolgung` | admin.provisionsmanagement.followup | auth role:staff can:provisionen-verwalten |
| GET | `admin/reports` | admin.reports | auth role:staff |
| GET | `admin/reports/neukunden` | admin.reports.neukunden | auth role:staff |
| POST | `admin/reports/neukunden/{id}/sichtbarkeit` | admin.reports.neukunden.sichtbarkeit | auth role:staff role:admin,manager |
| POST | `admin/reports/neukunden/{id}/werber` | admin.reports.neukunden.werber | auth role:staff role:admin,manager |
| GET | `admin/search` | admin.search | auth role:staff |
| GET | `admin/service-pages` | admin.service_pages | auth role:staff role:admin,manager |
| POST | `admin/service-pages` | admin.service_pages.store | auth role:staff role:admin,manager |
| GET | `admin/service-pages/create` | admin.service_pages.create | auth role:staff role:admin,manager |
| PUT | `admin/service-pages/{servicePage}` | admin.service_pages.update | auth role:staff role:admin,manager |
| DELETE | `admin/service-pages/{servicePage}` | admin.service_pages.delete | auth role:staff role:admin,manager |
| GET | `admin/service-pages/{servicePage}/edit` | admin.service_pages.edit | auth role:staff role:admin,manager |
| POST | `admin/service-pages/{servicePage}/toggle` | admin.service_pages.toggle | auth role:staff role:admin,manager |
| GET | `admin/settings` | admin.settings | auth role:staff role:admin |
| PUT | `admin/settings` | admin.settings.update | auth role:staff role:admin |
| GET | `admin/signaturen` | admin.signatures.index | auth role:staff |
| POST | `admin/signaturen` | admin.signatures.store | auth role:staff throttle:60,10 |
| GET | `admin/signaturen/kunden-suche` | admin.signatures.customer_search | auth role:staff |
| GET | `admin/signaturen/neu` | admin.signatures.create | auth role:staff |
| GET | `admin/signaturen/{id}` | admin.signatures.show | auth role:staff |
| DELETE | `admin/signaturen/{id}` | admin.signatures.destroy | auth role:staff |
| POST | `admin/signaturen/{id}/abbrechen` | admin.signatures.cancel | auth role:staff |
| GET | `admin/signaturen/{id}/download/{which?}` | admin.signatures.download | auth role:staff |
| POST | `admin/signaturen/{id}/erinnern` | admin.signatures.remind | auth role:staff throttle:60,10 |
| GET | `admin/signaturen/{id}/protokoll` | admin.signatures.audit | auth role:staff |
| GET | `admin/signaturen/{id}/seite/{page}` | admin.signatures.page | auth role:staff throttle:600,1 |
| POST | `admin/signaturen/{id}/senden` | admin.signatures.send | auth role:staff throttle:60,10 |
| GET | `admin/signaturen/{id}/vorbereiten` | admin.signatures.prepare | auth role:staff |
| POST | `admin/signaturen/{id}/vorbereiten` | admin.signatures.prepare.save | auth role:staff throttle:300,10 |
| POST | `admin/signaturen/{id}/zuordnen` | admin.signatures.assign | auth role:staff |
| GET | `admin/systemzustand` | admin.system_health | auth role:staff role:admin,manager |
| GET | `admin/systemzustand.json` | admin.system_health.json | auth role:staff role:admin,manager |
| GET | `admin/tarifrechner` | admin.tarifrechner | auth role:staff role:admin,manager |
| POST | `admin/tarifrechner` | admin.tarifrechner.store | auth role:staff role:admin,manager |
| POST | `admin/tarifrechner/reorder` | admin.tarifrechner.reorder | auth role:staff role:admin,manager |
| DELETE | `admin/tarifrechner/{id}` | admin.tarifrechner.destroy | auth role:staff role:admin,manager |
| GET | `admin/tasks` | admin.tasks | auth role:staff |
| POST | `admin/tasks` | admin.tasks.store | auth role:staff |
| GET | `admin/tasks/kunden-suche` | admin.tasks.customer_search | auth role:staff throttle:120,1 |
| PUT | `admin/tasks/{id}` | admin.tasks.update | auth role:staff |
| DELETE | `admin/tasks/{id}` | admin.tasks.destroy | auth role:staff |
| GET | `admin/team` | admin.team.verwaltung | auth role:staff role:admin,manager |
| POST | `admin/team/substitution` | admin.team.substitution.store | auth role:staff role:admin,manager |
| DELETE | `admin/team/substitution/{id}` | admin.team.substitution.destroy | auth role:staff role:admin,manager |
| POST | `admin/team/transfer` | admin.team.transfer | auth role:staff role:admin,manager |
| GET | `admin/tickets` | admin.tickets | auth role:staff |
| POST | `admin/tickets/bulk` | admin.tickets.bulk | auth role:staff |
| GET | `admin/tickets/statistik` | admin.tickets.stats | auth role:staff role:admin,manager |
| GET | `admin/tickets/{id}` | admin.ticket | auth role:staff |
| DELETE | `admin/tickets/{id}` | admin.ticket.delete | auth role:staff role:admin,manager |
| DELETE | `admin/tickets/{id}/force` | admin.ticket.forcedelete | auth role:staff role:admin |
| POST | `admin/tickets/{id}/note` | admin.ticket.note | auth role:staff |
| POST | `admin/tickets/{id}/reply` | admin.ticket.reply | auth role:staff |
| POST | `admin/tickets/{id}/restore` | admin.ticket.restore | auth role:staff role:admin,manager |
| POST | `admin/tickets/{id}/status` | admin.ticket.status | auth role:staff |
| POST | `admin/tickets/{id}/update` | admin.ticket.update | auth role:staff |
| GET | `admin/vermittler-abrechnung` | admin.vermittler.index | auth role:staff role:admin,manager |
| GET | `admin/vermittler-abrechnung/bericht` | admin.vermittler.report | auth role:staff role:admin,manager |
| POST | `admin/vermittler-abrechnung/datensatz/{id}/zuordnen` | admin.vermittler.link | auth role:staff role:admin,manager |
| POST | `admin/vermittler-abrechnung/dokument/{id}/einlesen` | admin.vermittler.from_document | auth role:staff role:admin,manager |
| POST | `admin/vermittler-abrechnung/import` | admin.vermittler.import | auth role:staff role:admin,manager |
| GET | `admin/vermittler-abrechnung/pruefung` | admin.vermittler.review | auth role:staff role:admin,manager |
| GET | `admin/vermittler-abrechnung/vertrag-suche` | admin.vermittler.contract_search | auth role:staff role:admin,manager |
| POST | `admin/vermittler-abrechnung/vorgangsliste` | admin.vermittler.vorgangsliste | auth role:staff role:admin,manager |
| GET | `admin/vermittler-abrechnung/{id}` | admin.vermittler.show | auth role:staff role:admin,manager |
| GET | `admin/verwaltung` | admin.verwaltung | auth role:staff |
| GET | `admin/vorlagen` | admin.templates | auth role:staff |
| POST | `admin/vorlagen` | admin.templates.store | auth role:staff role:admin,manager |
| GET | `admin/vorlagen/liste` | admin.templates.list | auth role:staff |
| POST | `admin/vorlagen/standard` | admin.templates.seed | auth role:staff role:admin,manager |
| PUT | `admin/vorlagen/{id}` | admin.templates.update | auth role:staff role:admin,manager |
| DELETE | `admin/vorlagen/{id}` | admin.templates.destroy | auth role:staff role:admin,manager |
| GET | `admin/vorlagen/{id}/render` | admin.templates.render | auth role:staff |
| GET | `admin/werbung` | admin.werbung | auth role:staff role:admin,manager |
| GET | `admin/werbung/neu/{banner}` | admin.werbung.neu | auth role:staff role:admin,manager |
| POST | `admin/werbung/neu/{banner}` | admin.werbung.store | auth role:staff role:admin,manager |
| POST | `admin/werbung/schutzgrenze` | admin.werbung.cap | auth role:staff role:admin,manager role:admin |
| POST | `admin/werbung/{campaignId}/budget` | admin.werbung.budget | auth role:staff role:admin,manager |
| POST | `admin/werbung/{campaignId}/delete` | admin.werbung.delete | auth role:staff role:admin,manager |
| POST | `admin/werbung/{campaignId}/status` | admin.werbung.status | auth role:staff role:admin,manager |
| POST | `api/website-assistent` | api.assistant.send | throttle:10,1 |
| GET | `api/website-assistent/status` | api.assistant.status | throttle:60,1 |
| GET | `api/website-assistent/verlauf` | api.assistant.history | throttle:60,1 |
| POST | `api/website-contact` | api.contact.store | throttle:10,1 |
| GET | `api/website-contact/token` | api.contact.token | throttle:30,1 |
| POST | `api/website-inquiry` | api.inquiry.store | throttle:30,1 |
| GET | `ar` | ar.website.home | forceLocale:ar |
| GET | `ar/kontakt/danke` | ar.website.thanks | forceLocale:ar |
| GET | `ar/leistungen` | ar.services.index | forceLocale:ar |
| GET | `ar/leistungen/{slug}` | ar.services.show | forceLocale:ar |
| GET | `ar/versicherungsmakler-hamburg` | ar.website.hamburg | forceLocale:ar |
| GET | `ar/{page}` | ar.legal | forceLocale:ar |
| GET | `confirm-password` | password.confirm | auth |
| POST | `confirm-password` |  | auth throttle:6,1 |
| POST | `email/verification-notification` | verification.send | auth throttle:6,1 |
| GET | `forgot-password` | password.request | guest |
| POST | `forgot-password` | password.email | guest throttle:passwort-reset |
| GET | `forgot-password/gesendet` | password.request.sent | guest |
| GET | `gesundheit` | health.pulse | throttle:60,1 healthtoken |
| GET | `hilfe` | support.form |  |
| POST | `hilfe` | support.submit | throttle:8,1 |
| GET | `index.html` |  |  |
| POST | `kontakt` | website.contact.submit | throttle:8,1 |
| GET | `kontakt/danke` | website.thanks |  |
| GET | `leistungen` | services.index |  |
| GET | `leistungen/{slug}` | services.show |  |
| POST | `leistungen/{slug}/anfrage` | services.submit | throttle:8,1 |
| GET | `login` | login | guest |
| POST | `login` |  | guest throttle:anmeldung |
| POST | `logout` | logout | auth |
| GET | `magic-login/{user}` | magic.login | signed throttle:10,1 |
| GET | `partner` | partner.dashboard | auth role:partner |
| GET | `partner/kunden` | partner.customers | auth role:partner |
| GET | `partner/kunden/{id}` | partner.customer | auth role:partner |
| GET | `partner/profil` | partner.profile | auth role:partner |
| POST | `partner/profil` | partner.profile.update | auth role:partner |
| GET | `partner/provisionen` | partner.commissions | auth role:partner |
| PUT | `password` | password.update | auth |
| GET | `passwort-festlegen` | password.forced | auth |
| POST | `passwort-festlegen` | password.forced.store | auth throttle:10,1 |
| GET | `portal` | portal.dashboard | auth role:customer |
| GET | `portal/addresses` | portal.addresses | auth role:customer |
| POST | `portal/addresses` | portal.addresses.store | auth role:customer |
| POST | `portal/addresses/{id}/change` | portal.addresses.change | auth role:customer |
| GET | `portal/attachments/{id}/download` | portal.attachment.download | auth role:customer |
| GET | `portal/bank` | portal.bank | auth role:customer |
| POST | `portal/bank` | portal.bank.store | auth role:customer |
| GET | `portal/banner/{id}/interesse` | portal.banner.interest | auth role:customer |
| GET | `portal/banner/{id}/klick` | portal.banner.click | auth role:customer |
| POST | `portal/banner/{id}/schliessen` | portal.banner.dismiss | auth role:customer |
| GET | `portal/change-requests` | portal.change_requests | auth role:customer |
| GET | `portal/contacts` | portal.contacts | auth role:customer |
| POST | `portal/contacts` | portal.contacts.store | auth role:customer |
| POST | `portal/contacts/{id}/change` | portal.contacts.change | auth role:customer |
| GET | `portal/contracts` | portal.contracts | auth role:customer |
| POST | `portal/contracts/report` | portal.contracts.report | auth role:customer |
| GET | `portal/contracts/{id}` | portal.contracts.show | auth role:customer |
| POST | `portal/contracts/{id}/change` | portal.contracts.change | auth role:customer |
| POST | `portal/contracts/{id}/kilometerstand` | portal.contracts.mileage | auth role:customer throttle:10,1 |
| POST | `portal/contracts/{id}/zaehlerstand` | portal.contracts.meter | auth role:customer throttle:10,1 |
| GET | `portal/datenschutz` | portal.datenschutz | auth role:customer |
| POST | `portal/document-requests/{id}/upload` | portal.document_requests.upload | auth role:customer throttle:20,10 |
| GET | `portal/documents` | portal.documents | auth role:customer |
| POST | `portal/documents` | portal.documents.upload | auth role:customer throttle:20,10 |
| POST | `portal/documents/scan` | portal.documents.scan | auth role:customer throttle:20,10 |
| GET | `portal/documents/{id}/analyse-status` | portal.documents.analyse_status | auth role:customer throttle:120,1 |
| GET | `portal/documents/{id}/download` | portal.documents.download | auth role:customer |
| GET | `portal/documents/{id}/view` | portal.documents.view | auth role:customer |
| GET | `portal/email-connection` | portal.email_connection | auth role:customer |
| POST | `portal/email-connection/grant` | portal.email_connection.grant | auth role:customer |
| POST | `portal/email-connection/revoke` | portal.email_connection.revoke | auth role:customer |
| GET | `portal/family` | portal.family | auth role:customer |
| POST | `portal/family` | portal.family.store | auth role:customer |
| POST | `portal/family/{id}/change` | portal.family.change | auth role:customer |
| POST | `portal/family/{id}/delete` | portal.family.delete | auth role:customer |
| GET | `portal/nachrichten` | portal.messages | auth role:customer |
| POST | `portal/nachrichten` | portal.messages.store | auth role:customer |
| GET | `portal/nachrichten/anhang/{id}` | portal.messages.attachment | auth role:customer |
| GET | `portal/nachrichten/anhang/{id}/ansehen` | portal.messages.attachment.view | auth role:customer |
| GET | `portal/nachrichten/feed` | portal.messages.feed | auth role:customer throttle:120,1 |
| GET | `portal/notifications` | portal.notifications | auth role:customer |
| POST | `portal/notifications/{id}/read` | portal.notifications.read | auth role:customer |
| GET | `portal/profile` | portal.profile | auth role:customer |
| POST | `portal/profile` | portal.profile.update | auth role:customer |
| POST | `portal/profile/password` | portal.profile.password | auth role:customer |
| GET | `portal/tickets` | portal.tickets | auth role:customer |
| POST | `portal/tickets` | portal.tickets.store | auth role:customer throttle:20,10 |
| GET | `portal/tickets/create` | portal.tickets.create | auth role:customer |
| GET | `portal/tickets/{id}` | portal.tickets.show | auth role:customer |
| POST | `portal/tickets/{id}/close` | portal.tickets.close | auth role:customer |
| POST | `portal/tickets/{id}/rate` | portal.tickets.rate | auth role:customer |
| POST | `portal/tickets/{id}/reply` | portal.tickets.reply | auth role:customer throttle:30,10 |
| GET | `register` | register | guest |
| POST | `register` |  | guest throttle:registrierung |
| GET | `register/bestaetigen/{token}` | register.verify | guest throttle:12,1 |
| GET | `register/bestaetigung-noetig` | register.pending | guest |
| POST | `register/erneut-senden` | register.resend | guest throttle:registrierung |
| POST | `reset-password` | password.store | throttle:passwort-reset |
| GET | `reset-password/{token}` | password.reset |  |
| GET | `robots.txt` | seo.robots |  |
| GET | `s/{code}` | social.redirect | throttle:120,1 |
| GET | `sicherheit/bestaetigen` | two_factor.challenge | auth |
| POST | `sicherheit/bestaetigen` | two_factor.challenge.store | auth throttle:20,1 |
| GET | `sicherheit/ersatzcodes` | two_factor.recovery_codes | auth |
| POST | `sicherheit/ersatzcodes` | two_factor.recovery_codes.renew | auth throttle:10,1 |
| GET | `sicherheit/zwei-faktor` | two_factor.setup | auth |
| POST | `sicherheit/zwei-faktor` | two_factor.setup.store | auth throttle:10,1 |
| GET | `sitemap.xml` | seo.sitemap |  |
| GET | `sprache/{locale}` | locale.switch |  |
| GET | `unterschreiben/{token}` | signature.show | throttle:signatur |
| POST | `unterschreiben/{token}/ablehnen` | signature.decline | throttle:signatur |
| POST | `unterschreiben/{token}/bestaetigen` | signature.verify | throttle:signatur |
| POST | `unterschreiben/{token}/code` | signature.code | throttle:signatur |
| GET | `unterschreiben/{token}/dokument` | signature.document | throttle:signatur |
| GET | `unterschreiben/{token}/fertig` | signature.done | throttle:signatur |
| GET | `unterschreiben/{token}/firmenbild/{asset}` | signature.company_image | throttle:signatur |
| POST | `unterschreiben/{token}/identitaet` | signature.identity | throttle:signatur throttle:10,10 |
| GET | `unterschreiben/{token}/seite/{page}` | signature.page | throttle:signatur |
| POST | `unterschreiben/{token}/unterschreiben` | signature.sign | throttle:signatur |
| GET | `up` |  |  |
| GET | `verify-email` | verification.notice | auth |
| GET | `verify-email/{id}/{hash}` | verification.verify | auth signed throttle:6,1 |
| GET | `versicherungsmakler-hamburg` | website.hamburg |  |
| GET | `webhooks/whatsapp` | webhooks.whatsapp.verify | throttle:300,1 |
| POST | `webhooks/whatsapp` | webhooks.whatsapp.handle | throttle:300,1 |
| GET | `website` | website.home |  |
| GET | `zugang/passwort-festlegen/{user}` | password.setup | signed:relative throttle:20,1 |
| POST | `zugang/passwort-festlegen/{user}` | password.setup.store | signed:relative throttle:10,1 |
| GET | `{page}` | legal |  |
| GET | `{page}.html` |  |  |
