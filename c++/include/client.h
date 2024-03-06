#pragma once

#include <string>

namespace wesrv {
    using namespace std;
    class Client {
        public:
            Client(int socket, string address, int port);
            int getSocket();
            void dumpClient();
            ~Client();
        private:
            int socket;
            string address;
            int port;
            string nonce;
            bool auth;
    };
}