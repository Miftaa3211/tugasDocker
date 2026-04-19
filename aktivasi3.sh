curl -s -X POST "https://api.cloudflare.com/client/v4/zones/3902676282e7d9649acadfde7e43278f/dns_records" \
     -H "X-Auth-Email: Mftharizky@gmail.com" \
     -H "X-Auth-Key: c57c3a383e270f1e3978515157339c40474e1" \
     -H "Content-Type: application/json" \
     --data '{"type":"A","name":"unik.tugaspkl.my.id","content":"192.168.19.156","ttl":120,"priority":10,"proxied":true}' > /dev/null
