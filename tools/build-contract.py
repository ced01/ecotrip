"""Generate the checked-in OpenAPI contract and explicit offline UI examples."""
import json
from pathlib import Path

def ref(n): return {'$ref': '#/components/schemas/'+n}
def arr(item): return {'type':'array','items':item}
def obj(props, required=None, **extra): return {'type':'object','additionalProperties':False,'properties':props,'required':required if required is not None else list(props), **extra}
def string(**kw): return {'type':'string',**kw}
def enum(*values): return string(enum=list(values))
def integer(minimum=0, **kw): return {'type':'integer','minimum':minimum,**kw}
def number(**kw): return {'type':'number',**kw}
def nullable(schema): return dict(schema, nullable=True)
id_schema=string(pattern='^[a-z0-9][a-z0-9_-]{0,63}$')
mode=enum('train','coach','walk','public_transport','bicycle','carpool','flight')
date=string(format='date')
time=string(format='date-time')
S={}
S['Mode']=mode
S['Problem']=obj({'type':string(format='uri-reference'),'title':string(),'status':integer(400,maximum=599),'detail':string(),'instance':string(format='uri-reference'),'code':enum('invalid_json','validation_failed','not_found','method_not_allowed','payload_too_large','unsupported_media_type','rate_limited','provider_unavailable','internal_error'),'violations':arr(obj({'path':string(),'message':string()}))},['type','title','status','detail','instance','code'])
S['Source']=obj({'id':id_schema,'publisher':string(),'url':nullable(string(format='uri')),'license':nullable(string()),'accessedAt':nullable(date),'version':nullable(string()),'reuseNotes':string(),'dataStatus':enum('demo','verified','unverified')})
S['Provenance']=obj({'status':enum('demo','verified','unverified','unknown'),'sourceIds':arr(id_schema),'asOf':nullable(time),'note':string()})
S['Place']=obj({'id':id_schema,'name':string(),'countryCode':string(pattern='^[A-Z]{2}$'),'latitude':nullable(number(minimum=-90,maximum=90)),'longitude':nullable(number(minimum=-180,maximum=180)),'timezone':string(example='Europe/Paris'),'provenance':ref('Provenance')})
S['Page']=obj({'limit':integer(1,maximum=50),'offset':integer(0,maximum=10000),'total':integer()})
S['PlacesResponse']=obj({'items':arr(ref('Place')),'page':ref('Page'),'sources':arr(ref('Source'))})
S['Capabilities']=obj({'mode':enum('demo','real'),'countries':arr(string()),'modes':arr(ref('Mode')),'coveredPairs':arr(obj({'originId':id_schema,'destinationId':id_schema})),'limits':obj({'maxTravelers':integer(1,maximum=9),'maxResultsPerDirection':integer(1,maximum=20),'maxBodyBytes':integer(1,maximum=16384),'bookingAvailable':{'type':'boolean','enum':[False]}}),'warnings':arr(string())})
S['TripRequest']=obj({'originId':id_schema,'destinationId':id_schema,'departureDate':date,'returnDate':nullable(date),'travelers':integer(1,maximum=9),'modes':dict(arr(ref('Mode')),minItems=1,maxItems=7,uniqueItems=True)},['originId','destinationId','departureDate','travelers','modes'],description='Dates locales Europe/Paris pour le catalogue MVP. Départ >= aujourd’hui selon horloge serveur injectable, retour >= départ; origine distincte destination. JSON <= 16384 octets. Identifiants inconnus: 422. Une paire connue non couverte: 200/out_of_coverage.')
S['Distance']=obj({'km':nullable(number(minimum=0)),'method':enum('scenario','routed','great_circle','unknown'),'provenance':ref('Provenance')})
S['Leg']=obj({'id':id_schema,'mode':ref('Mode'),'subtype':nullable(string()),'originId':id_schema,'destinationId':id_schema,'durationMinutes':integer(),'waitingMinutes':integer(),'distance':ref('Distance'),'schedule':obj({'departureAt':nullable(time),'arrivalAt':nullable(time),'provenance':ref('Provenance')}),'provenance':ref('Provenance')},description='Les durées de scénario ne sont pas des horaires. waitingMinutes est l’attente avant cette étape; pas de double comptage. Aucun horaire déduit de la seule date demandée.')
S['Factor']=obj({'id':id_schema,'value':number(minimum=0),'unit':enum('kgCO2e/passenger-km','kgCO2e/vehicle-km'),'mode':ref('Mode'),'subtype':nullable(string()),'geography':string(),'validFrom':date,'validUntil':nullable(date),'scope':enum('operation','life_cycle'),'occupancy':nullable(number(exclusiveMinimum=True,minimum=0)),'version':string(),'sourceId':id_schema,'status':enum('verified','synthetic_test')})
S['LegEstimate']=obj({'legId':id_schema,'status':enum('complete','unavailable','demo'),'kgCO2ePerTraveler':nullable(number(minimum=0)),'kgCO2eGroup':nullable(number(minimum=0)),'factorId':nullable(id_schema),'reason':nullable(string())})
S['EmissionEstimate']=obj({'status':enum('complete','partial','unavailable','demo'),'kgCO2ePerTraveler':nullable(number(minimum=0)),'kgCO2eGroup':nullable(number(minimum=0)),'coveredDistanceKm':number(minimum=0),'totalDistanceKm':nullable(number(minimum=0)),'comparable':{'type':'boolean'},'comparisonKey':nullable(string()),'methodologyVersion':string(),'assumptions':arr(string()),'legs':arr(ref('LegEstimate')),'factors':arr(ref('Factor'))},description='Inconnu = null, jamais zéro. Partiel/indisponible non classable carbone. comparisonKey lie unité, périmètre et méthode compatibles; demo reste explicitement synthétique. Les sommes partielles décrivent uniquement les étapes couvertes.')
S['Itinerary']=obj({'id':id_schema,'direction':enum('outbound','inbound'),'requestedDate':date,'dataStatus':enum('demo','real'),'legs':dict(arr(ref('Leg')),minItems=1),'durationMinutes':integer(),'transfers':integer(),'emissions':ref('EmissionEstimate'),'provenance':ref('Provenance'),'warnings':arr(string())},description='durationMinutes = somme des durées et attentes. transfers compte les changements de véhicule motorisé; marche de liaison non comptée comme véhicule. Les retours sont recherchés séparément.')
S['DirectionResult']=obj({'status':enum('complete','partial','empty','out_of_coverage','unavailable','not_requested'),'itineraries':dict(arr(ref('Itinerary')),maxItems=20),'warnings':arr(string())})
S['JourneysResponse']=obj({'dataMode':enum('demo','real'),'request':ref('TripRequest'),'outbound':ref('DirectionResult'),'inbound':ref('DirectionResult'),'sources':arr(ref('Source')),'warnings':arr(string())},description='200 empty: couvert mais aucun résultat selon filtres. out_of_coverage: paire hors catalogue. unavailable: échec partiel lorsque autre direction réussit; panne totale = 503. inbound not_requested si pas de retour. Aucun fallback réel vers demo.')
S['Evidence']=obj({'id':id_schema,'claim':string(),'kind':enum('declaration','certification'),'status':enum('declared','unverified','verified','expired','demo'),'organization':nullable(string()),'referenceUrl':nullable(string(format='uri')),'validFrom':nullable(date),'validUntil':nullable(date),'checkedAt':nullable(date),'sourceId':id_schema},description='verified nécessite organisme, référence vérifiable et date de vérification; validité contrôlée à la lecture. Une déclaration ne devient jamais une certification. Une certification synthétique reste demo, jamais verified.')
S['Price']=obj({'amount':number(minimum=0),'currency':string(pattern='^[A-Z]{3}$'),'basis':enum('night_per_room','night_per_person'),'asOf':date,'sourceId':id_schema,'dataStatus':enum('demo','verified','unverified')})
S['Accommodation']=obj({'id':id_schema,'name':string(),'destinationId':id_schema,'dataStatus':enum('demo','real'),'features':obj({'bicycleParking':nullable({'type':'boolean'}),'publicTransportNearby':nullable({'type':'boolean'})}),'publicTransportDistanceMeters':nullable(integer()),'price':nullable({'allOf':[ref('Price')]}),'evidence':arr(ref('Evidence')),'provenance':ref('Provenance')},description='Pas de disponibilité, réservation ni émission hôtelière. Inconnu est null, différent de false. Distance transport non renseignée reste null.')
S['AccommodationsResponse']=obj({'status':enum('complete','empty','out_of_coverage'),'items':arr(ref('Accommodation')),'page':ref('Page'),'sources':arr(ref('Source')),'warnings':arr(string())})
S['Methodology']=obj({'version':string(),'status':enum('unavailable','demo','verified'),'indicator':enum('kgCO2e'),'scopes':arr(enum('operation','life_cycle')),'rules':arr(string()),'limits':arr(string()),'sources':arr(ref('Source'))})
S['TripPlan']=obj({'schemaVersion':{'type':'integer','enum':[1]},'savedAt':time,'request':ref('TripRequest'),'outbound':ref('Itinerary'),'inbound':nullable({'allOf':[ref('Itinerary')]}),'accommodation':nullable({'allOf':[ref('Accommodation')]}),'sources':arr(ref('Source'))},description='Format de brouillon local volontaire, jamais envoyé au serveur dans ce MVP; restaurer après validation, afficher ancienneté, permettre effacement.')
S['Health']=obj({'status':enum('ok'),'service':enum('ecotrip')})

def param(name,schema,required=False,description=''):
    return {'name':name,'in':'query','required':required,'schema':schema,'description':description or name}
def response(schema,example=None):
    media={'schema':ref(schema)}
    if example: media['example']=example
    return {'description':'Réponse conforme au contrat','content':{'application/json':media}}
errors={str(code):{'description':desc,'content':{'application/problem+json':{'schema':ref('Problem')}}} for code,desc in [(400,'JSON malformé'),(404,'Ressource inconnue'),(405,'Méthode non autorisée'),(413,'Corps trop grand'),(415,'Type de contenu non pris en charge'),(422,'Critères invalides'),(429,'Limite atteinte; Retry-After en secondes'),(503,'Fournisseur indisponible'),(500,'Erreur interne sans détail sensible')]}
errors['429']['headers']={'Retry-After':{'schema':integer(1),'description':'Délai en secondes'}}

def op(operation_id,schema,parameters=None,request=False):
    result={'operationId':operation_id,'summary':operation_id,'x-implementation-status':'planned','responses':{'200':response(schema),**errors}}
    if parameters: result['parameters']=parameters
    if request: result['requestBody']={'required':True,'content':{'application/json':{'schema':ref('TripRequest')}}}
    return result
page=[param('limit',integer(1,maximum=50,default=20)),param('offset',integer(0,maximum=10000,default=0))]
paths={
'/health':{'get':{'operationId':'health','summary':'Vivacité du processus uniquement, sans test DB','x-implementation-status':'implemented','responses':{'200':response('Health')}}},
'/api/v1/capabilities':{'get':op('capabilities','Capabilities')},
'/api/v1/places':{'get':op('places','PlacesResponse',[param('q',string(maxLength=100,default='')),*page])},
'/api/v1/journeys/search':{'post':op('searchJourneys','JourneysResponse',request=True)},
'/api/v1/accommodations':{'get':dict(op('accommodations','AccommodationsResponse',[param('destinationId',id_schema,True),param('bicycleParking',enum('true','false','unknown'),description='Omis: aucun filtre; true, false et unknown sélectionnent chacun uniquement cet état.'),param('publicTransportNearby',enum('true','false','unknown'),description='Omis: aucun filtre; true, false et unknown sélectionnent chacun uniquement cet état.'),*page]),**{'x-implementation-status':'implemented'})},
'/api/v1/methodology':{'get':dict(op('methodology','Methodology'),**{'x-implementation-status':'implemented'})},
}
provenance={'status':'demo','sourceIds':['demo-contract'],'asOf':None,'note':'Exemple synthétique de contrat, sans offre réelle.'}
source={'id':'demo-contract','publisher':'Ecotrip — fixture synthétique','url':None,'license':None,'accessedAt':None,'version':'contract-v1','reuseNotes':'Tests et développement UI uniquement; aucune attribution scientifique.','dataStatus':'demo'}
places=[{'id':i,'name':n,'countryCode':'FR','latitude':None,'longitude':None,'timezone':'Europe/Paris','provenance':provenance} for i,n in [('demo-paris','Paris (scénario)'),('demo-lyon','Lyon (scénario)')]]
request={'originId':'demo-paris','destinationId':'demo-lyon','departureDate':'2027-01-15','returnDate':None,'travelers':1,'modes':['train','coach']}
def itinerary(mode,duration):
    leg={'id':'demo-leg-'+mode,'mode':mode,'subtype':None,'originId':'demo-paris','destinationId':'demo-lyon','durationMinutes':duration,'waitingMinutes':0,'distance':{'km':None,'method':'unknown','provenance':provenance},'schedule':{'departureAt':None,'arrivalAt':None,'provenance':provenance},'provenance':provenance}
    return {'id':'demo-'+mode,'direction':'outbound','requestedDate':request['departureDate'],'dataStatus':'demo','legs':[leg],'durationMinutes':duration,'transfers':0,'emissions':{'status':'unavailable','kgCO2ePerTraveler':None,'kgCO2eGroup':None,'coveredDistanceKm':0,'totalDistanceKm':None,'comparable':False,'comparisonKey':None,'methodologyVersion':'foundation-v1','assumptions':[],'legs':[{'legId':leg['id'],'status':'unavailable','kgCO2ePerTraveler':None,'kgCO2eGroup':None,'factorId':None,'reason':'Aucun facteur réel vérifié ni distance disponible.'}],'factors':[]},'provenance':provenance,'warnings':['Durée synthétique; aucun horaire disponible.']}
examples={
'capabilities':{'mode':'demo','countries':['FR'],'modes':['train','coach'],'coveredPairs':[{'originId':'demo-paris','destinationId':'demo-lyon'}],'limits':{'maxTravelers':9,'maxResultsPerDirection':20,'maxBodyBytes':16384,'bookingAvailable':False},'warnings':['Fixture hors ligne, API métier non implémentée en TASK-0001.']},
'places':{'items':places,'page':{'limit':20,'offset':0,'total':2},'sources':[source]},
'journeys':{'dataMode':'demo','request':request,'outbound':{'status':'complete','itineraries':[itinerary('train',150),itinerary('coach',390)],'warnings':[]},'inbound':{'status':'not_requested','itineraries':[],'warnings':[]},'sources':[source],'warnings':['Exemple de contrat uniquement.']},
'accommodations':{'status':'complete','items':[{'id':'demo-stay','name':'Hébergement fictif de contrat','destinationId':'demo-lyon','dataStatus':'demo','features':{'bicycleParking':None,'publicTransportNearby':None},'publicTransportDistanceMeters':None,'price':None,'evidence':[],'provenance':provenance}],'page':{'limit':20,'offset':0,'total':1},'sources':[source],'warnings':['Aucune certification, prix ou disponibilité réels annoncés.']},
'methodology':{'version':'carbon-estimation-v1','status':'demo','indicator':'kgCO2e','scopes':['operation','life_cycle'],'rules':['Calcul séparé pour chaque étape à partir de sa distance.','Facteur passager-km : distance × facteur par voyageur.','Facteur véhicule-km : distance × facteur ÷ occupation par voyageur.','Une distance ou émission inconnue vaut null, jamais zéro.','Les facteurs véhicule-km exigent une occupation explicite.','Seules des estimations complètes de même unité, périmètre et méthode sont comparables.'],'limits':['Aucun facteur réel n’est livré : les calculs intégrés aux tests utilisent uniquement des facteurs synthétiques.','Les sommes partielles ne permettent aucun classement carbone.','Pas de bilan hôtelier, de forçage radiatif ni de score écologique global.'],'sources':[{'id':'synthetic-tests','publisher':'Ecotrip','url':None,'license':None,'accessedAt':None,'version':'carbon-estimation-v1','reuseNotes':'Facteurs arithmétiques fictifs réservés aux tests; aucune donnée environnementale réelle.','dataStatus':'demo'}]},
'problem':{'type':'about:blank','title':'Unprocessable Content','status':422,'detail':'Critères invalides.','instance':'/api/v1/journeys/search','code':'validation_failed','violations':[{'path':'travelers','message':'Valeur attendue entre 1 et 9.'}]},
}
for name,schema,path,method in [('capabilities','Capabilities','/api/v1/capabilities','get'),('places','PlacesResponse','/api/v1/places','get'),('journeys','JourneysResponse','/api/v1/journeys/search','post'),('accommodations','AccommodationsResponse','/api/v1/accommodations','get'),('methodology','Methodology','/api/v1/methodology','get')]:
    paths[path][method]['responses']['200']=response(schema,examples[name])
contract={'openapi':'3.0.3','info':{'title':'Ecotrip internal API','version':'1.2.0','description':'Contrat MVP. /health, /api/v1/methodology et le catalogue d’hébergements synthétique hors ligne sont implémentés; les autres endpoints métier restent planifiés. Les exemples sont synthétiques et hors ligne. Même origine, pas de compte ni réservation.'},'servers':[{'url':'/'}],'paths':paths,'components':{'schemas':S}}
Path('docs/examples').mkdir(parents=True,exist_ok=True)
# JSON is a strict YAML subset and avoids introducing a Python runtime dependency.
Path('docs/openapi.yaml').write_text(json.dumps(contract,ensure_ascii=False,indent=2)+'\n')
for name,example in examples.items(): Path('docs/examples/'+name+'.json').write_text(json.dumps(example,ensure_ascii=False,indent=2)+'\n')
