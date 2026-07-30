-- A beérkezett levél sora azelőtt jön létre, hogy a mellékleteit feldolgoznánk:
-- így a nyers payload akkor is megmarad, ha a feldolgozás elhasal, és az
-- egyedi (company_id, provider_message_id) már az ismételt webhook-kézbesítést
-- is kizárja, nem csak a végén.
alter type public.inbound_email_status add value 'received' before 'processed';
