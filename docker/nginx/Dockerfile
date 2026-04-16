FROM nginx:alpine
RUN apk add --no-cache openssl
COPY entrypoint.sh /opt/entrypoint.sh
RUN chmod +x /opt/entrypoint.sh
COPY tls.conf /etc/nginx/conf.d/default.conf
ENTRYPOINT ["/opt/entrypoint.sh"]
